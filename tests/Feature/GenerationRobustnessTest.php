<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Credits\CreditLedger;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Covers gaps not handled by PixelforgeWebhookTest and SubmitGenerationJobTest:
 * multi-event ordering, unknown generations, credit blocking, 503 retries,
 * idempotent replays, daily limits and the sidebar pages.
 */
class GenerationRobustnessTest extends TestCase
{
    use RefreshDatabase;

    private const STARTING_BALANCE = 5_000_000_000;

    private const RESERVE = 22_000_000;

    private User $user;

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pixelforge.base_url' => 'https://pixelforge.test/v1',
            'pixelforge.api_key' => 'pk_test',
            'pixelforge.webhook_secret' => 'whsec_test',
        ]);

        $this->user = User::factory()->create();
        app(CreditLedger::class)->purchase($this->user, 5);

        $this->generation = Generation::create([
            'user_id' => $this->user->id,
            'idempotency_key' => (string) Str::uuid(),
            'provider_id' => 'gen_test_1',
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'status' => GenerationStatus::Queued,
            'estimated_charge_nanos' => 20_000_000,
            'reserved_nanos' => self::RESERVE,
        ]);

        app(CreditLedger::class)->reserve($this->user, $this->generation, self::RESERVE);
    }

    private function pricing(): array
    {
        return [
            'object' => 'pricing',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    private function object(string $status, array $overrides = []): array
    {
        return array_merge([
            'id' => 'gen_test_1',
            'object' => 'generation',
            'status' => $status,
            'metadata' => ['app_generation_id' => (string) $this->generation->id],
            'rates' => $this->pricing()['rates'],
            'usage' => $status === 'succeeded'
                ? ['input_text_tokens' => 3, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 100, 'total_tokens' => 383]
                : null,
            'image_url' => $status === 'succeeded' ? 'https://pixelforge.test/images/gen_test_1.png' : null,
            'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'Could not draw it.'] : null,
            'created_at' => '2026-11-17T10:00:00Z',
            'started_at' => '2026-11-17T10:00:02Z',
            'completed_at' => in_array($status, ['succeeded', 'failed'], true) ? '2026-11-17T10:00:20Z' : null,
        ], $overrides);
    }

    private function sendWebhook(string $eventId, string $type, array $object): TestResponse
    {
        $body = json_encode(['id' => $eventId, 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => app(WebhookSignature::class)->sign($body, time()),
        ], $body);
    }

    private function runJob(): SubmitGenerationToPixelforge
    {
        $job = (new SubmitGenerationToPixelforge($this->generation->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    }

    public function test_processing_then_failed_then_succeeded_stays_failed_and_free(): void
    {
        $this->sendWebhook('evt_1', 'generation.processing', $this->object('processing'))->assertNoContent();
        $this->sendWebhook('evt_2', 'generation.failed', $this->object('failed'))->assertNoContent();
        $this->sendWebhook('evt_3', 'generation.succeeded', $this->object('succeeded'))->assertNoContent();

        $generation = $this->generation->fresh();

        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame(0, $generation->charge_nanos);
        $this->assertNull($generation->image_url);
        $this->assertSame(self::STARTING_BALANCE, $this->user->fresh()->balance_nanos);
        $this->assertFalse(CreditTransaction::where('generation_id', $generation->id)->where('type', 'settle')->exists());
        $this->assertSame(3, WebhookEvent::count());
    }

    public function test_a_webhook_for_an_unknown_generation_is_acknowledged_and_changes_nothing(): void
    {
        $this->sendWebhook('evt_unknown', 'generation.succeeded', $this->object('succeeded', [
            'id' => 'gen_somebody_else',
            'metadata' => [],
        ]))->assertNoContent();

        $this->assertSame(GenerationStatus::Queued, $this->generation->fresh()->status);
        $this->assertSame(self::STARTING_BALANCE - self::RESERVE, $this->user->fresh()->balance_nanos);
        $this->assertTrue(WebhookEvent::where('event_id', 'evt_unknown')->exists());
    }

    public function test_store_is_blocked_when_the_user_cannot_afford_the_maximum_cost(): void
    {
        Http::fake(['pixelforge.test/v1/pricing' => Http::response($this->pricing())]);
        Queue::fake();

        $poorUser = User::factory()->create();

        $this->actingAs($poorUser)
            ->from('/generate')
            ->post('/generations', ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertRedirect('/generate')
            ->assertSessionHasErrors('credits');

        $this->assertSame(0, Generation::where('user_id', $poorUser->id)->count());
        $this->assertSame(0, $poorUser->fresh()->balance_nanos);
        Queue::assertNothingPushed();
    }

    public function test_store_reserves_the_maximum_and_queues_submission(): void
    {
        Http::fake(['pixelforge.test/v1/pricing' => Http::response($this->pricing())]);
        Queue::fake();

        $this->actingAs($this->user)
            ->post('/generations', ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertRedirect('/generations');

        $created = Generation::latest('id')->first();

        $this->assertSame(GenerationStatus::Queued, $created->status);
        $this->assertSame(-$created->reserved_nanos, CreditTransaction::where('generation_id', $created->id)->where('type', 'reserve')->value('amount_nanos'));
        Queue::assertPushed(SubmitGenerationToPixelforge::class, fn ($job) => $job->generationId === $created->id);
    }

    public function test_an_overloaded_503_is_retried_after_retry_after_with_the_same_idempotency_key(): void
    {
        $this->generation->update(['provider_id' => null]);

        Http::fakeSequence('pixelforge.test/*')
            ->push(['error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Busy.']], 503, ['Retry-After' => '7'])
            ->push($this->object('queued', ['id' => 'gen_after_retry']), 202);

        $first = $this->runJob();
        $first->assertReleased();
        $this->assertGreaterThanOrEqual(7, $first->job->releaseDelay);
        $this->assertSame(GenerationStatus::Queued, $this->generation->fresh()->status);
        $this->assertNull($this->generation->fresh()->provider_id);

        $this->runJob()->assertNotReleased();

        $keys = Http::recorded()->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])->unique();
        $this->assertSame([$this->generation->idempotency_key], $keys->values()->all());
        $this->assertSame('gen_after_retry', $this->generation->fresh()->provider_id);
        $this->assertSame(self::STARTING_BALANCE - self::RESERVE, $this->user->fresh()->balance_nanos);
    }

    public function test_an_idempotent_replay_never_moves_a_generation_backwards(): void
    {
        $this->sendWebhook('evt_1', 'generation.processing', $this->object('processing'))->assertNoContent();
        $this->generation->update(['provider_id' => null]);

        Http::fake(['pixelforge.test/*' => Http::response($this->object('queued'), 202, ['Idempotent-Replayed' => 'true'])]);

        $this->runJob();

        $generation = $this->generation->fresh();

        $this->assertSame(GenerationStatus::Processing, $generation->status);
        $this->assertSame('gen_test_1', $generation->provider_id);
        $this->assertSame(self::STARTING_BALANCE - self::RESERVE, $this->user->fresh()->balance_nanos);
    }

    public function test_a_daily_limit_error_fails_immediately_for_free(): void
    {
        $this->generation->update(['provider_id' => null]);

        Http::fake(['pixelforge.test/*' => Http::response([
            'error' => ['type' => 'rate_limit_error', 'code' => 'daily_limit_exceeded', 'message' => 'Daily limit reached.'],
        ], 429)]);

        $this->runJob()->assertNotReleased();

        $generation = $this->generation->fresh();

        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('daily_limit_exceeded', $generation->error_code);
        $this->assertSame(0, $generation->charge_nanos);
        $this->assertSame(self::STARTING_BALANCE, $this->user->fresh()->balance_nanos);
    }

    public function test_the_sidebar_pages_load(): void
    {
        Http::fake([
            'pixelforge.test/v1/pricing' => Http::response($this->pricing()),
            'pixelforge.test/v1/usage*' => Http::response(['object' => 'usage', 'data' => []]),
        ]);

        $this->actingAs($this->user);

        $this->get('/generate')->assertOk();
        $this->get('/generations')->assertOk();
        $this->get('/billing')->assertOk();
        $this->get('/admin/report')->assertForbidden();

        $this->user->forceFill(['is_admin' => true])->save();

        $this->get('/admin/report')->assertOk();
    }

    public function test_the_estimate_matches_the_documented_example(): void
    {
        Http::fake(['pixelforge.test/v1/pricing' => Http::response($this->pricing())]);

        $this->actingAs($this->user)
            ->getJson('/generations/estimate?prompt_characters=400&size=1536x1024&quality=high&reference_images=2')
            ->assertOk()
            ->assertJsonPath('provider_cost.max', 373_875_000)
            ->assertJsonPath('charge.max', 467_343_750);
    }
}
