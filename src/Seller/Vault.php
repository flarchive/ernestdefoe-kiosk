<?php

namespace Ernestdefoe\Kiosk\Seller;

use Flarum\Foundation\Paths;
use RuntimeException;

/**
 * Seals members' payout secrets (their PayPal app secret, a NOWPayments key…).
 *
 * libsodium secretbox, with a key kept in a file under storage/ (created on
 * first use, readable only by the web server's user), so a copy of the
 * database alone reveals nothing. The forum's own keys stay in settings as
 * before: these belong to other people.
 */
class Vault
{
    private ?string $key = null;

    public function __construct(private Paths $paths)
    {
    }

    /** @param array<string, string> $secrets */
    public function seal(array $secrets): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox((string) json_encode($secrets), $nonce, $this->key()));
    }

    /** @return array<string, string> empty when it can't be opened (wrong or lost key) */
    public function open(?string $sealed): array
    {
        $raw = base64_decode((string) $sealed, true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return [];
        }

        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key());
        $data = $plain === false ? null : json_decode($plain, true);

        return is_array($data) ? array_map('strval', $data) : [];
    }

    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        $file = $this->paths->storage.'/kiosk/seller.key';

        if (! is_file($file)) {
            if (! is_dir(dirname($file)) && ! @mkdir(dirname($file), 0700, true) && ! is_dir(dirname($file))) {
                throw new RuntimeException('Kiosk cannot create '.dirname($file));
            }
            // Two requests at once: the first file written wins, the other reads it.
            $handle = @fopen($file, 'x');
            if ($handle) {
                fwrite($handle, base64_encode(sodium_crypto_secretbox_keygen()));
                fclose($handle);
                @chmod($file, 0600);
            }
        }

        $key = base64_decode(trim((string) @file_get_contents($file)), true);

        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Kiosk\'s seller key at '.$file.' is unreadable');
        }

        return $this->key = $key;
    }
}
