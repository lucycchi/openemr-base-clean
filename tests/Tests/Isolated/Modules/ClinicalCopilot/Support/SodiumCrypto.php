<?php

/**
 * A real authenticated-encryption CryptoInterface for isolated tests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support;

use OpenEMR\Common\Crypto\CryptoInterface;
use OpenEMR\Common\Crypto\KeySource;

/**
 * libsodium secretbox with a per-instance random key. Unlike a pass-through
 * fake, a flipped byte or a token from another instance really fails to
 * open, so tests of ConversationTurns exercise genuine tamper detection
 * without CryptoGen's key files on disk. Only the "standard" methods are
 * implemented; the database/filesystem ones are not used by the module.
 */
final class SodiumCrypto implements CryptoInterface
{
    private const PREFIX = 'sb1';
    private readonly string $key;

    public function __construct()
    {
        $this->key = sodium_crypto_secretbox_keygen();
    }

    public function encryptStandard(?string $value, KeySource $keySource = KeySource::Drive): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($value ?? '', $nonce, $this->key));
    }

    public function decryptStandard(?string $value, KeySource $keySource = KeySource::Drive, ?int $minimumVersion = null): false|string
    {
        if (!$this->cryptCheckStandard($value)) {
            return false;
        }
        $raw = base64_decode(substr((string) $value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return false;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
    }

    public function cryptCheckStandard(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    public function encryptForDatabase(?string $value): string
    {
        throw new \LogicException('not used by the module');
    }

    public function encryptForFilesystem(?string $value): string
    {
        throw new \LogicException('not used by the module');
    }

    public function decryptFromDatabase(?string $value, ?int $minimumVersion = null): string
    {
        throw new \LogicException('not used by the module');
    }

    public function decryptFromFilesystem(?string $value): string
    {
        throw new \LogicException('not used by the module');
    }

    public function isDatabaseValueLatest(string $value): bool
    {
        throw new \LogicException('not used by the module');
    }

    public function isFilesystemValueLatest(string $value): bool
    {
        throw new \LogicException('not used by the module');
    }
}
