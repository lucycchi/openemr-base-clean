<?php

/**
 * Runtime configuration from the environment (.env via OpenEMR globals).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class Config
{
    public function __construct(
        public string $openAiApiKey,
        public string $openAiModel,
        public string $langfuseHost,
        public string $langfusePublicKey,
        public string $langfuseSecretKey,
        public ?float $inputUsdPerMillion = null,
        public ?float $outputUsdPerMillion = null,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            self::env('OPENAI_API_KEY'),
            self::env('OPENAI_MODEL') ?: 'gpt-4o-mini',
            self::env('LANGFUSE_HOST') ?: (self::env('LANGFUSE_BASE_URL') ?: 'https://cloud.langfuse.com'),
            self::env('LANGFUSE_PUBLIC_KEY'),
            self::env('LANGFUSE_SECRET_KEY'),
            self::envFloat('OPENAI_INPUT_USD_PER_M'),
            self::envFloat('OPENAI_OUTPUT_USD_PER_M'),
        );
    }

    public function hasOpenAi(): bool
    {
        return $this->openAiApiKey !== '';
    }

    public function hasLangfuse(): bool
    {
        return $this->langfusePublicKey !== '' && $this->langfuseSecretKey !== '';
    }

    private static function envFloat(string $name): ?float
    {
        $v = self::env($name);
        return is_numeric($v) ? (float) $v : null;
    }

    private static function env(string $name): string
    {
        $v = $_ENV[$name] ?? getenv($name);
        return is_string($v) ? trim($v) : '';
    }
}
