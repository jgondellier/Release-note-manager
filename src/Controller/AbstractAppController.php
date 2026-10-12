<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

abstract class AbstractAppController extends AbstractController
{
    /** Vérifie le jeton CSRF d'une action POST (champ « _token »). */
    protected function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->getPayload()->get('_token', ''))) {
            throw new AccessDeniedHttpException('Jeton de sécurité invalide ou expiré. Rechargez la page et réessayez.');
        }
    }
}
