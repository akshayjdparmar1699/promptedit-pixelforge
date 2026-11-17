<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubmitGenerationJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pixelforge.base_url' => 'https://pixelforge.test/v1', 'pixelforge.api_key' => 'pk_test']);

        $this->user = User::factory()->create();
        app(CreditLedger::class)->purchase($this->user, 5);

        $this->generation = Generation::create([
            'user_id' => $this->user->id,
            'idempotency_key' => (string) Str::uuid(),
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

    private function runJob(): SubmitGenerationToPixelforge
    {
        $job = (new SubmitGenerationToPixelforge($this->generation->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    }

    public function test_it_sends_the_idempotency_key_and_stores_the_provider_id(): void
    {
        Http::fake(['pixelforge.test/*' => Http::response([
            'id' => 'gen_abc',
            'object' => 'generation',
            'status' => 'queued',
            'rates' => ['input_text_tokens' => '5.00'],
            'created_at' => '2026-11-17T10:00:00Z',
        ], 202)]);

        $this->runJob();

        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $this->generation->idempotency_key)
            && $request['metadata']['app_generation_id'] === (string) $this->generation->id);

        $this->assertSame('gen_abc', $this->generation->fresh()->provider_id);
    }

    public function test_it_waits_and_retries_when_the_concurrency_limit_is_reached(): void
    {
        Http::fake(['pixelforge.test/*' => Http::response([
            'error' => ['type' => 'rate_limit_error', 'code' => 'concurrency_limit_exceeded', 'message' => 'Too many.'],
        ], 429, ['Retry-After' => '5'])]);

        $this->runJob()->assertReleased();

        $generation = $this->generation->fresh();

        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertStringContainsString('free slot', $generation->status_detail);
    }

    public function test_a_content_policy_rejection_fails_for_free(): void
    {
        Http::fake(['pixelforge.test/*' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Rejected.'],
        ], 400)]);

        $this->runJob();

        $generation = $this->generation->fresh();

        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame(0, $generation->charge_nanos);
        $this->assertSame(5_000_000_000, $this->user->fresh()->balance_nanos);
    }
}
