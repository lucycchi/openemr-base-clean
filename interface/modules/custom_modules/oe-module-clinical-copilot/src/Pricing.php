<?php

/**
 * Turns token counts into US dollars so cost is a first-class number in
 * every log line and trace, not something reconstructed later from a bill.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

/**
 * Turns token counts into an estimated USD cost for logs and traces.
 * Two sources of rates: a small built-in list-price table, and optional
 * per-deployment overrides from Config (environment variables). Overrides
 * win when set; otherwise the table is used; if the model is in neither,
 * cost is unknown (null) rather than guessed.
 */
final readonly class Pricing
{
    /**
     * USD per one million tokens, [input, output], OpenAI list prices as of
     * 2026-09. Override with OPENAI_INPUT_USD_PER_M / OPENAI_OUTPUT_USD_PER_M
     * when the contract price differs or a model is missing here.
     *
     * @var array<string, array{float, float}>
     */
    private const LIST_USD_PER_MILLION = [
        'gpt-4o-mini' => [0.15, 0.60],
        'gpt-4o' => [2.50, 10.00],
        'gpt-4.1-mini' => [0.40, 1.60],
        'gpt-4.1' => [2.00, 8.00],
    ];

    public function __construct(
        private ?float $inputUsdPerMillion = null,
        private ?float $outputUsdPerMillion = null,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->inputUsdPerMillion, $config->outputUsdPerMillion);
    }

    /** Null when neither an override nor a list price is known for the model. */
    public function costUsd(string $model, int $promptTokens, int $completionTokens): ?float
    {
        [$input, $output] = $this->ratesFor($model);
        if ($input === null || $output === null) {
            return null;
        }
        // Rates are per million tokens; round to micro-dollars.
        return round(($promptTokens * $input + $completionTokens * $output) / 1_000_000, 6);
    }

    /**
     * [input rate, output rate], each possibly null. Override ?? list price.
     *
     * @return array{?float, ?float}
     */
    private function ratesFor(string $model): array
    {
        $list = self::LIST_USD_PER_MILLION[$model] ?? [null, null];
        return [$this->inputUsdPerMillion ?? $list[0], $this->outputUsdPerMillion ?? $list[1]];
    }
}
