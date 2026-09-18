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

/**
 * All deployment settings the module reads, parsed once from environment
 * variables into typed, immutable fields. Nothing else in the module calls
 * getenv(); tests construct this directly with literal values. Empty string
 * means "not set" for the string fields; has*() helpers answer the common
 * "is this integration configured?" questions.
 */
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
        /** Shared token a webhook sender may present to alerts.php (X-Alert-Token). */
        public string $alertWebhookSecret = '',
        /** Langfuse webhook signing secret (lf-whs-…) used to verify x-langfuse-signature. */
        public string $langfuseWebhookSecret = '',
        /** Kill switch for the scheduled pre-warm; off unless COPILOT_PREWARM_ENABLED is truthy. */
        public bool $prewarmEnabled = false,
    ) {
    }

    /** The production constructor. Defaults: gpt-4o-mini, Langfuse cloud host. */
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
            self::env('ALERT_WEBHOOK_SECRET'),
            self::env('LANGFUSE_WEBHOOK_SECRET'),
            self::envFlag('COPILOT_PREWARM_ENABLED'),
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

    /** "1", "true", "yes", "on" (any case) are true; everything else, including unset, is false. */
    private static function envFlag(string $name): bool
    {
        return in_array(strtolower(self::env($name)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function envFloat(string $name): ?float
    {
        $v = self::env($name);
        return is_numeric($v) ? (float) $v : null;
    }

    /** Reads $_ENV first, then getenv(), trimmed; '' when unset. */
    private static function env(string $name): string
    {
        $v = $_ENV[$name] ?? getenv($name);
        return is_string($v) ? trim($v) : '';
    }
}
