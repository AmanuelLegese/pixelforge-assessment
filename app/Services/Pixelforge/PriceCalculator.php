<?php

namespace App\Services\Pixelforge;

use App\Support\Money;

class PriceCalculator
{
    private const TOKEN_FIELDS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * Pixelforge's cost in nanodollars: sum(tokens * rate per 1M) / 1M, rounded half up.
     *
     * @param  array<string,int>  $usage  token counts keyed like the API's usage object
     * @param  array<string,string>  $rates  USD per 1M tokens, keyed like the API's rates object
     */
    public function providerCost(array $usage, array $rates): int
    {
        // tokens * (USD per 1M) / 1M * 1e9 nano = tokens * rate * 1000 nano
        $total = '0';
        foreach (self::TOKEN_FIELDS as $field) {
            $total = bcadd($total, bcmul((string) ($usage[$field] ?? 0), (string) $rates[$field], 6), 6);
        }

        return (int) bcadd(bcmul($total, '1000', 6), '0.5', 0);
    }

    /** What the user pays: provider cost plus the markup, rounded half up to a nanodollar. */
    public function charge(int $providerCostNano): int
    {
        $multiplier = 100 + (int) config('pixelforge.markup_percent');

        return intdiv($providerCostNano * $multiplier + 50, 100);
    }

    /**
     * Pre-submit estimate. Text tokens and reasoning tokens are only known afterwards,
     * so we return a typical value plus a worst case (used for affordability checks).
     *
     * @param  array<string,mixed>  $pricing  decoded GET /v1/pricing
     * @return array{typical:int, max:int, min:int}
     */
    public function estimate(array $pricing, string $prompt, string $size, string $quality, int $referenceImages): array
    {
        $tokens = $pricing['tokens'];
        $rates = $pricing['rates'];

        $text = (int) ceil(mb_strlen($prompt) / $tokens['characters_per_text_token']);
        $variance = (string) $tokens['text_token_variance'];
        $textMin = (int) floor((float) bcmul((string) $text, bcsub('1', $variance, 4), 4));
        $textMax = (int) ceil((float) bcmul((string) $text, bcadd('1', $variance, 4), 4));

        $base = [
            'input_image_tokens' => $referenceImages * $tokens['per_reference_image'],
            'output_tokens' => $tokens['output'][$quality][$size],
        ];
        $reasoning = $tokens['reasoning'][$quality];

        $cost = fn (int $text, int $reasoningTokens) => $this->charge($this->providerCost(
            $base + ['input_text_tokens' => $text, 'reasoning_tokens' => $reasoningTokens],
            $rates,
        ));

        return [
            'min' => $cost($textMin, $reasoning['min']),
            'typical' => $cost($text, intdiv($reasoning['min'] + $reasoning['max'], 2)),
            'max' => $cost($textMax, $reasoning['max']),
        ];
    }
}
