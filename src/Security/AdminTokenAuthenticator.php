<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Authentification par token d'administration (formulaire POST /login + CSRF).
 * La session est régénérée après succès (stratégie « migrate » de Symfony).
 */
final class AdminTokenAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';
    public const ADMIN_IDENTIFIER = 'admin';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AdminTokenVerifier $verifier,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $token = (string) $request->getPayload()->get('_admin_token', '');
        $csrf = (string) $request->getPayload()->get('_csrf_token', '');

        // Identifiant fixe : sert aussi de clé à la limitation des tentatives (avec l'IP).
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, '');

        if (!$this->verifier->isConfigured()) {
            throw new CustomUserMessageAuthenticationException('La connexion est désactivée : le token d\'administration n\'est pas configuré sur le serveur.');
        }

        return new Passport(
            new UserBadge(self::ADMIN_IDENTIFIER),
            new CustomCredentials(
                fn (string $credentials): bool => $this->verifier->verify($credentials),
                $token
            ),
            [new CsrfTokenBadge('authenticate', $csrf)]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_dashboard'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}
