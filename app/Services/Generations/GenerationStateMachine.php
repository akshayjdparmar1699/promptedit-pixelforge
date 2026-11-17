<?php

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Credits\CreditLedger;
use App\Services\Pricing\CostCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single place where a generation changes state. Webhooks, the submit job and the
 * polling fallback all feed provider generation objects into apply(), so every path
 * follows the same rules:
 *
 *  - status only moves forward (queued -> processing -> succeeded|failed);
 *  - succeeded and failed are final and never change again;
 *  - money moves exactly once, when the generation reaches a final state.
 */
class GenerationStateMachine
{
    public function __construct(
        private readonly CostCalculator $calculator,
        private readonly CreditLedger $ledger,
    ) {}

    /** @param  array<string, mixed>  $object  A Pixelforge generation object. */
    public function apply(Generation $generation, array $object): Generation
    {
        return DB::transaction(function () use ($generation, $object) {
            $generation = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            $this->linkToProvider($generation, $object);

            $incoming = GenerationStatus::tryFrom((string) ($object['status'] ?? ''));

            if ($incoming && $generation->status->canMoveTo($incoming)) {
                match ($incoming) {
                    GenerationStatus::Processing => $this->markProcessing($generation, $object),
                    GenerationStatus::Succeeded => $this->markSucceeded($generation, $object),
                    GenerationStatus::Failed => $this->markFailed(
                        $generation,
                        $object['error']['code'] ?? 'generation_failed',
                        $object['error']['message'] ?? 'The image could not be generated.',
                        $object['completed_at'] ?? null,
                    ),
                    GenerationStatus::Queued => null,
                };
            }

            $generation->save();

            return $generation;
        });
    }

    /** Marks a generation failed when Pixelforge never accepted it (validation, content policy, retries exhausted). */
    public function failBeforeAcceptance(Generation $generation, string $code, string $message): Generation
    {
        return DB::transaction(function () use ($generation, $code, $message) {
            $generation = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if (! $generation->status->isFinal()) {
                $this->markFailed($generation, $code, $message, null);
                $generation->save();
            }

            return $generation;
        });
    }

    /** @param  array<string, mixed>  $object */
    private function linkToProvider(Generation $generation, array $object): void
    {
        if (! $generation->provider_id && ! empty($object['id'])) {
            $generation->provider_id = $object['id'];
        }

        if (! $generation->rates && ! empty($object['rates'])) {
            $generation->rates = $object['rates'];
        }

        if (! $generation->provider_created_at && ! empty($object['created_at'])) {
            $generation->provider_created_at = Carbon::parse($object['created_at']);
        }

        if (! $generation->status->isFinal() && ($object['status'] ?? null) !== null) {
            $generation->status_detail = null;
        }
    }

    /** @param  array<string, mixed>  $object */
    private function markProcessing(Generation $generation, array $object): void
    {
        $generation->status = GenerationStatus::Processing;
        $generation->started_at = isset($object['started_at']) ? Carbon::parse($object['started_at']) : now();
    }

    /** @param  array<string, mixed>  $object */
    private function markSucceeded(Generation $generation, array $object): void
    {
        $usage = $object['usage'];
        $rates = $object['rates'] ?? $generation->rates;

        $providerCost = $this->calculator->providerCostNanos($usage, $rates);
        $charge = $this->calculator->chargeNanos($providerCost);

        $generation->status = GenerationStatus::Succeeded;
        $generation->usage = $usage;
        $generation->rates = $rates;
        $generation->provider_cost_nanos = $providerCost;
        $generation->charge_nanos = $charge;
        $generation->image_url = $object['image_url'] ?? null;
        $generation->image_url_expires_at = isset($object['image_url_expires_at']) ? Carbon::parse($object['image_url_expires_at']) : null;
        $generation->started_at ??= isset($object['started_at']) ? Carbon::parse($object['started_at']) : null;
        $generation->completed_at = isset($object['completed_at']) ? Carbon::parse($object['completed_at']) : now();

        $this->ledger->settle($generation, $charge);
    }

    private function markFailed(Generation $generation, string $code, string $message, ?string $completedAt): void
    {
        $generation->status = GenerationStatus::Failed;
        $generation->status_detail = null;
        $generation->error_code = $code;
        $generation->error_message = $message;
        $generation->provider_cost_nanos = 0;
        $generation->charge_nanos = 0;
        $generation->completed_at = $completedAt ? Carbon::parse($completedAt) : now();

        $this->ledger->release($generation);
    }
}
