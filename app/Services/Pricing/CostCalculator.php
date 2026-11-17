<?php

namespace App\Services\Pricing;

use App\Support\Money;

/**
 * Pure pricing math. No I/O, so it is fully unit tested.
 *
 * Rates are USD per 1M tokens. Converting a rate to nanodollars per 1M tokens
 * keeps it an integer; the division by 1M happens once, on the summed total.
 */
final class CostCalculator
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function __construct(private readonly int $markupPercent = 25) {}

    /**
     * Exact provider cost of a finished generation, from its usage and the rates it was billed at.
     *
     * @param  array<string, int>  $usage
     * @param  array<string, string>  $rates
     */
    public function providerCostNanos(array $usage, array $rates): int
    {
        $numerator = 0;

        foreach (self::TOKEN_TYPES as $type) {
            $numerator += (int) ($usage[$type] ?? 0) * Money::decimalToNanos((string) ($rates[$type] ?? '0'));
        }

        // With rates of at most 3 decimal places this division is always exact.
        // Pixelforge never rounds, so any remainder is rounded half up to the nearest nanodollar.
        return intdiv($numerator + 500_000, 1_000_000);
    }

    /**
     * What the user pays: provider cost plus markup, rounded up to a whole nanodollar
     * so we never charge less than cost + markup.
     */
    public function chargeNanos(int $providerCostNanos): int
    {
        $scaled = $providerCostNanos * (100 + $this->markupPercent);

        return intdiv($scaled + 99, 100);
    }

    /**
     * Token and cost estimate before submitting, based on the published pricing document.
     *
     * Returns the cheapest, expected and most expensive outcomes. "max" is what we reserve
     * from the user's balance, because the real cost can never exceed it.
     *
     * @param  array<string, mixed>  $pricing  The body of GET /v1/pricing.
     * @return array{tokens: array<string, array<string, int>>, provider_cost: array<string, int>, charge: array<string, int>}
     */
    public function estimate(array $pricing, int $promptCharacters, string $size, string $quality, int $referenceImages): array
    {
        $t = $pricing['tokens'];

        // Integer ceiling division, so no float is involved in the token count.
        $charactersPerToken = max(1, (int) $t['characters_per_text_token']);
        $textEstimate = intdiv($promptCharacters + $charactersPerToken - 1, $charactersPerToken);

        // The variance arrives as a JSON number (0.15); it is converted to whole basis points once.
        $varianceBasisPoints = (int) round(((float) $t['text_token_variance']) * 10_000);

        $text = [
            'min' => intdiv($textEstimate * (10_000 - $varianceBasisPoints), 10_000),
            'expected' => $textEstimate,
            'max' => intdiv($textEstimate * (10_000 + $varianceBasisPoints) + 9_999, 10_000),
        ];

        $image = $referenceImages * (int) $t['per_reference_image'];
        $output = (int) $t['output'][$quality][$size];

        $reasoningMin = (int) $t['reasoning'][$quality]['min'];
        $reasoningMax = (int) $t['reasoning'][$quality]['max'];
        $reasoning = [
            'min' => $reasoningMin,
            'expected' => intdiv($reasoningMin + $reasoningMax, 2),
            'max' => $reasoningMax,
        ];

        $tokens = [];
        $providerCost = [];
        $charge = [];

        foreach (['min', 'expected', 'max'] as $case) {
            $tokens[$case] = [
                'input_text_tokens' => $text[$case],
                'input_image_tokens' => $image,
                'output_tokens' => $output,
                'reasoning_tokens' => $reasoning[$case],
            ];
            $providerCost[$case] = $this->providerCostNanos($tokens[$case], $pricing['rates']);
            $charge[$case] = $this->chargeNanos($providerCost[$case]);
        }

        return ['tokens' => $tokens, 'provider_cost' => $providerCost, 'charge' => $charge];
    }
}
