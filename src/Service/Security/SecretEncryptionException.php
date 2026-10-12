<?php

declare(strict_types=1);

namespace App\Service\Security;

/**
 * Erreur de chiffrement/déchiffrement. Le message ne contient jamais le secret ni la clé.
 */
final class SecretEncryptionException extends \RuntimeException
{
}
