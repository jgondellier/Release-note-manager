<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Vérifie le token d'administration fourni à la connexion.
 *
 * Le token de référence ne vit que dans la variable d'environnement ADMIN_TOKEN :
 * il n'est jamais écrit en base, dans les journaux ni dans les messages d'erreur.
 * La comparaison est en temps constant (hash_equals sur des empreintes de longueur fixe).
 */
final class AdminTokenVerifier
{
    public const MIN_LENGTH = 32;

    public function __construct(
        #[Autowire(env: 'ADMIN_TOKEN')]
        private readonly string $adminToken,
    ) {
    }

    public function isConfigured(): bool
    {
        return \strlen(trim($this->adminToken)) >= self::MIN_LENGTH;
    }

    public function verify(string $candidate): bool
    {
        if (!$this->isConfigured() || '' === $candidate) {
            return false;
        }

        return hash_equals(
            hash('sha256', trim($this->adminToken)),
            hash('sha256', trim($candidate))
        );
    }
}
