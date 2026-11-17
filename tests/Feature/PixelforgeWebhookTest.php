<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const STARTING_BALANCE = 5_000_000_000;

    // 3*5000 + 280*40000 + 100*12500 = 12,465,000 nanodollars; +25% = 15,581,250.
    private const EXPECTED_COST = 12_465_000;

    private const EXPECTED_CHARGE = 15_581_250;

    private User $user;

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pixelforge.webhook_secret' => 'whsec_test']);

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
            'reserved_nanos' => 22_000_000,
        ]);

        app(CreditLedger::class)->reserve($this->user, $this->generation, 22_000_000);
    }

    private function generationObject(string $status, array $overrides = []): array
    {
        return array_merge([
            'id' => 'gen_test_1',
            'object' => 'generation',
            'status' => $status,
            'metadata' => [],
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'usage' => $status === 'succeeded' ? [
                'input_text_tokens' => 3,
                'input_image_tokens' => 0,
                'output_tokens' => 280,
                'reasoning_tokens' => 100,
                'total_tokens' => 383,
            ] : null,
            'image_url' => $status === 'succeeded' ? 'https://pixelforge.promptedit.dev/images/gen_test_1.png' : null,
            'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'Could not draw it.'] : null,
            'created_at' => '2026-11-17T10:00:00Z',
            'started_at' => '2026-11-17T10:00:02Z',
            'completed_at' => in_array($status, ['succeeded', 'failed'], true) ? '2026-11-17T10:00:20Z' : null,
        ], $overrides);
    }

    private function sendWebhook(string $eventId, string $type, array $object, ?string $signature = null): TestResponse
    {
        $body = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-17T10:00:20Z',
            'data' => ['object' => $object],
        ]);

        return $this->sendRaw($body, $signature ?? app(WebhookSignature::class)->sign($body, time()));
    }

    private function sendRaw(string $body, string $signature): TestResponse
    {
        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $signature,
        ], $body);
    }

    public function test_it_rejects_a_bad_signature(): void
    {
        $this->sendWebhook('evt_1', 'generation.succeeded', $this->generationObject('succeeded'), 't='.time().',v1=deadbeef')
            ->assertStatus(400);

        $this->assertSame(GenerationStatus::Queued, $this->generation->fresh()->status);
    }

    public function test_it_rejects_an_old_timestamp_even_with_a_valid_signature(): void
    {
        $body = '{"id":"evt_old","type":"ping"}';

        $this->sendRaw($body, app(WebhookSignature::class)->sign($body, time() - 3600))
            ->assertStatus(400);
    }

    public function test_the_same_event_delivered_twice_is_charged_once(): void
    {
        $this->sendWebhook('evt_1', 'generation.succeeded', $this->generationObject('succeeded'))->assertNoContent();
        $this->sendWebhook('evt_1', 'generation.succeeded', $this->generationObject('succeeded'))->assertNoContent();

        $generation = $this->generation->fresh();

        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(self::EXPECTED_COST, $generation->provider_cost_nanos);
        $this->assertSame(self::EXPECTED_CHARGE, $generation->charge_nanos);
        $this->assertSame(1, CreditTransaction::where('generation_id', $generation->id)->where('type', 'settle')->count());
        $this->assertSame(self::STARTING_BALANCE - self::EXPECTED_CHARGE, $this->user->fresh()->balance_nanos);
    }

    public function test_a_different_event_repeating_a_final_state_changes_nothing(): void
    {
        $this->sendWebhook('evt_1', 'generation.succeeded', $this->generationObject('succeeded'))->assertNoContent();
        $this->sendWebhook('evt_2', 'generation.succeeded', $this->generationObject('succeeded'))->assertNoContent();

        $this->assertSame(self::STARTING_BALANCE - self::EXPECTED_CHARGE, $this->user->fresh()->balance_nanos);
    }

    public function test_a_late_processing_event_never_moves_a_succeeded_generation_back(): void
    {
        $this->sendWebhook('evt_2', 'generation.succeeded', $this->generationObject('succeeded'))->assertNoContent();
        $this->sendWebhook('evt_1', 'generation.processing', $this->generationObject('processing'))->assertNoContent();

        $this->assertSame(GenerationStatus::Succeeded, $this->generation->fresh()->status);
    }

    public function test_failed_generations_are_free(): void
    {
        $this->sendWebhook('evt_1', 'generation.failed', $this->generationObject('failed'))->assertNoContent();

        $generation = $this->generation->fresh();

        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame(0, $generation->charge_nanos);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertSame(self::STARTING_BALANCE, $this->user->fresh()->balance_nanos);
    }

    public function test_a_webhook_arriving_before_the_create_request_returned_is_matched_by_metadata(): void
    {
        $this->generation->update(['provider_id' => null]);

        $this->sendWebhook('evt_1', 'generation.processing', $this->generationObject('processing', [
            'id' => 'gen_early',
            'metadata' => ['app_generation_id' => (string) $this->generation->id],
        ]))->assertNoContent();

        $generation = $this->generation->fresh();

        $this->assertSame('gen_early', $generation->provider_id);
        $this->assertSame(GenerationStatus::Processing, $generation->status);
    }
}
