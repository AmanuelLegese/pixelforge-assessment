<?php

namespace Tests\Unit;

use App\Services\Pixelforge\PriceCalculator;
use App\Support\Money;
use Tests\TestCase;

class PriceCalculatorTest extends TestCase
{
    private const RATES = ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'];

    public function test_it_computes_the_documented_worked_maximum_exactly(): void
    {
        // high, 1536x1024, 400 chars (100 tokens +15% = 115), 2 reference images, max reasoning
        $cost = (new PriceCalculator)->providerCost([
            'input_text_tokens' => 115, 'input_image_tokens' => 1530, 'output_tokens' => 6450, 'reasoning_tokens' => 8000,
        ], self::RATES);

        $this->assertSame('0.373875000', Money::toUsd($cost));
    }

    public function test_markup_is_25_percent_of_the_exact_provider_cost(): void
    {
        config(['pixelforge.markup_percent' => 25]);
        $calculator = new PriceCalculator;
        $cost = $calculator->providerCost(['input_text_tokens' => 1, 'output_tokens' => 1], self::RATES);

        $this->assertSame(45_000, $cost); // (5 + 40) / 1e6 USD
        $this->assertSame(56_250, $calculator->charge($cost));
    }

    public function test_sub_cent_amounts_stay_exact(): void
    {
        $this->assertSame(1, Money::fromUsd('0.000000001'));
        $this->assertSame('$0.000575', Money::display(575_000));
        $this->assertSame('$5.00', Money::display(5_000_000_000));
        $this->assertSame('$0.37', Money::display(370_000_000));
    }
}
