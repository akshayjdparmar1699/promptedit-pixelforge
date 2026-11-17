<?php

namespace Tests\Unit;

use App\Services\Pricing\CostCalculator;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class CostCalculatorTest extends TestCase
{
    private const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    private function pricing(): array
    {
        return [
            'rates' => self::RATES,
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

    public function test_decimals_convert_to_nanodollars_and_back_without_loss(): void
    {
        $this->assertSame(373_875_000, Money::decimalToNanos('0.373875000'));
        $this->assertSame(12_500_000_000, Money::decimalToNanos('12.50'));
        $this->assertSame('0.169080000', Money::nanosToDecimal(169_080_000));
        $this->assertSame('-0.000000005', Money::nanosToDecimal(-5));
    }

    public function test_matches_the_worked_maximum_cost_example_from_the_docs(): void
    {
        $cost = (new CostCalculator)->providerCostNanos([
            'input_text_tokens' => 115,
            'input_image_tokens' => 1530,
            'output_tokens' => 6450,
            'reasoning_tokens' => 8000,
        ], self::RATES);

        $this->assertSame('0.373875000', Money::nanosToDecimal($cost));
    }

    public function test_matches_the_usage_endpoint_example(): void
    {
        $cost = (new CostCalculator)->providerCostNanos([
            'input_text_tokens' => 41,
            'input_image_tokens' => 0,
            'output_tokens' => 3300,
            'reasoning_tokens' => 2950,
        ], self::RATES);

        $this->assertSame('0.169080000', Money::nanosToDecimal($cost));
    }

    public function test_markup_is_25_percent_rounded_up_to_a_whole_nanodollar(): void
    {
        $calculator = new CostCalculator(25);

        $this->assertSame(467_343_750, $calculator->chargeNanos(373_875_000));
        $this->assertSame(2, $calculator->chargeNanos(1));
        $this->assertSame(0, $calculator->chargeNanos(0));
    }

    public function test_estimate_maximum_matches_the_docs_example(): void
    {
        $estimate = (new CostCalculator)->estimate($this->pricing(), 400, '1536x1024', 'high', 2);

        $this->assertSame(115, $estimate['tokens']['max']['input_text_tokens']);
        $this->assertSame(85, $estimate['tokens']['min']['input_text_tokens']);
        $this->assertSame(4500, $estimate['tokens']['expected']['reasoning_tokens']);
        $this->assertSame(373_875_000, $estimate['provider_cost']['max']);
        $this->assertSame(467_343_750, $estimate['charge']['max']);
    }

    public function test_estimate_for_an_empty_prompt_without_references(): void
    {
        $estimate = (new CostCalculator)->estimate($this->pricing(), 0, '1024x1024', 'low', 0);

        // 280 output tokens at $40/1M = $0.0112; reasoning adds 0 to 500 tokens at $12.50/1M.
        $this->assertSame(11_200_000, $estimate['provider_cost']['min']);
        $this->assertSame(17_450_000, $estimate['provider_cost']['max']);
    }
}