<?php

declare(strict_types=1);

namespace App\Enum;

enum AuthenticationType: string
{
    /** Identifiant (e-mail sur Cloud) + API token ou mot de passe. */
    case Basic = 'basic';
    /** Personal Access Token envoyé en en-tête « Authorization: Bearer » (Server/Data Center). */
    case Bearer = 'bearer';

    public function label(): string
    {
        return match ($this) {
            self::Basic => 'Basic (identifiant + token / mot de passe)',
            self::Bearer => 'Personal Access Token (Bearer)',
        };
    }

    public function requiresUsername(): bool
    {
        return self::Basic === $this;
    }
}
