<?php

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Credits\InsufficientCreditsException;
use App\Services\Pricing\CostCalculator;
use App\Services\Pricing\PricingCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerationService
{
    public function __construct(
        private readonly PricingCatalog $pricing,
        private readonly CostCalculator $calculator,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * @return array{tokens: array<string, array<string, int>>, provider_cost: array<string, int>, charge: array<string, int>}
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImages): array
    {
        return $this->calculator->estimate(
            $this->pricing->current(),
            mb_strlen($prompt),
            $size,
            $quality,
            $referenceImages,
        );
    }

    /**
     * Creates the local record, reserves the maximum possible charge, and queues submission.
     *
     * @param  list<string>  $referenceImages
     *
     * @throws InsufficientCreditsException
     */
    public function create(User $user, string $prompt, string $size, string $quality, array $referenceImages): Generation
    {
        $estimate = $this->estimate($prompt, $size, $quality, count($referenceImages));

        $generation = DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $estimate) {
            $generation = Generation::create([
                'user_id' => $user->id,
                'idempotency_key' => (string) Str::uuid(),
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => array_values($referenceImages),
                'status' => GenerationStatus::Queued,
                'status_detail' => 'Sending to Pixelforge',
                'estimated_charge_nanos' => $estimate['charge']['expected'],
                'reserved_nanos' => $estimate['charge']['max'],
            ]);

            $this->ledger->reserve($user, $generation, $estimate['charge']['max']);

            return $generation;
        });

        SubmitGenerationToPixelforge::dispatch($generation->id)->afterCommit();

        return $generation;
    }
}
