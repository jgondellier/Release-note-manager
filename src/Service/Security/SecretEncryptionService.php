<?php

declare(strict_types=1);

namespace App\Service\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffrement authentifié des secrets Jira/Confluence (libsodium secretbox :
 * XSalsa20-Poly1305) avec une clé externe APP_ENCRYPTION_KEY (base64, 32 octets).
 *
 * Format stocké : « v1:base64(nonce || ciphertext) ». Le préfixe de version
 * permet une évolution future de l'algorithme sans ambiguïté.
 */
class SecretEncryptionService
{
    private const PREFIX = 'v1:';

    public function __construct(
        #[Autowire(env: 'APP_ENCRYPTION_KEY')]
        private readonly string $encodedKey,
    ) {
    }

    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function isConfigured(): bool
    {
        try {
            $this->key();

            return true;
        } catch (SecretEncryptionException) {
            return false;
        }
    }

    public function encrypt(string $plainText): string
    {
        if ('' === $plainText) {
            throw new SecretEncryptionException('Impossible de chiffrer un secret vide.');
        }
        $key = $this->key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plainText, $nonce, $key);
        sodium_memzero($key);

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    public function decrypt(string $payload): string
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            throw new SecretEncryptionException('Format de secret chiffré non reconnu.');
        }
        $raw = base64_decode(substr($payload, \strlen(self::PREFIX)), true);
        if (false === $raw || \strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new SecretEncryptionException('Secret chiffré corrompu.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->key();
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        sodium_memzero($key);
        if (false === $plain) {
            throw new SecretEncryptionException('Déchiffrement impossible : clé APP_ENCRYPTION_KEY différente de celle utilisée au chiffrement, ou donnée altérée.');
        }

        return $plain;
    }

    private function key(): string
    {
        $encoded = trim($this->encodedKey);
        if ('' === $encoded) {
            throw new SecretEncryptionException('APP_ENCRYPTION_KEY n\'est pas configurée (php bin/console app:encryption:generate-key).');
        }
        $key = base64_decode($encoded, true);
        if (false === $key || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            throw new SecretEncryptionException('APP_ENCRYPTION_KEY doit être l\'encodage base64 de 32 octets.');
        }

        return $key;
    }
}
