<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Generations\GenerationStateMachine;
use App\Services\Pixelforge\PixelforgeApiException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends a generation to Pixelforge. Every attempt reuses the generation's Idempotency-Key,
 * so a retry after a timeout returns the original generation instead of creating (and
 * billing) a second one.
 */
class SubmitGenerationToPixelforge implements ShouldQueue
{
    use Queueable;

    /** Releases are bounded by submit_attempts below; this only caps unexpected exceptions. */
    public int $tries = 0;

    public int $maxExceptions = 3;

    public function __construct(public readonly int $generationId) {}

    public function handle(PixelforgeClient $client, GenerationStateMachine $states): void
    {
        $generation = Generation::find($this->generationId);

        if (! $generation || $generation->provider_id || $generation->status->isFinal()) {
            return;
        }

        $generation->increment('submit_attempts');

        try {
            $object = $client->createGeneration($generation->toProviderPayload(), $generation->idempotency_key);
        } catch (PixelforgeApiException $e) {
            $this->handleRejection($generation, $e, $states);

            return;
        }

        $states->apply($generation, $object);
    }

    public function failed(?Throwable $exception): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation && ! $generation->provider_id) {
            app(GenerationStateMachine::class)->failBeforeAcceptance(
                $generation,
                'submission_failed',
                'We could not send this image to Pixelforge. You were not charged.',
            );
        }
    }

    private function handleRejection(Generation $generation, PixelforgeApiException $e, GenerationStateMachine $states): void
    {
        $withinBudget = $generation->submit_attempts < (int) config('pixelforge.submit_max_attempts')
            && $generation->created_at->gt(now()->subMinutes((int) config('pixelforge.submit_give_up_after_minutes')));

        if ($e->isRetryable() && $withinBudget) {
            $generation->update(['status_detail' => $e->waitingMessage()]);
            $this->release($this->retryDelay($e, $generation->submit_attempts));

            return;
        }

        report($e);
        $states->failBeforeAcceptance($generation, $e->errorCode, $e->userMessage());
    }

    /** Honors Retry-After, adds exponential backoff for repeated failures and jitter to avoid bursts. */
    private function retryDelay(PixelforgeApiException $e, int $attempt): int
    {
        $backoff = min(60, 2 ** min($attempt, 6));
        $base = $e->retryAfter !== null ? max($e->retryAfter, $e->errorCode === 'overloaded' ? $backoff : 0) : $backoff;

        return $base + random_int(0, 2);
    }
}
