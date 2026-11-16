<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * « %env(admin_fingerprint:ADMIN_TOKEN)% » : empreinte stable du token d'administration, utilisée comme
 * « mot de passe » de l'utilisateur en mémoire « admin ».
 *
 * Sans mot de passe, Symfony en génère un à partir de l'identifiant du cache : chaque cache:clear
 * (mise à jour de l'application comprise) déconnectait alors tout le monde. L'empreinte (HMAC, jamais
 * le token lui-même) reste identique tant que ADMIN_TOKEN ne change pas ; le changer invalide les sessions.
 */
final class AdminFingerprintEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        return hash_hmac('sha256', (string) $getEnv($name), 'release-note-manager:admin-session');
    }

    public static function getProvidedTypes(): array
    {
        return ['admin_fingerprint' => 'string'];
    }
}
