<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * « Mises à jour » lance des commandes sur le poste et « Sauvegarde » écrit et sert des archives
 * contenant les secrets : accessibles uniquement depuis le poste lui-même (127.0.0.1),
 * sauf RNM_UPDATE_ALLOW_REMOTE=1.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
class LocalOnlyListener
{
    public function __construct(
        #[Autowire('%env(bool:default::RNM_UPDATE_ALLOW_REMOTE)%')]
        private readonly ?bool $allowRemote,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $screen = match (true) {
            str_starts_with($path, '/updates') => 'Les mises à jour de l\'application',
            str_starts_with($path, '/backups') => 'La sauvegarde de l\'application',
            default => null,
        };
        if ($this->allowRemote || !$event->isMainRequest() || null === $screen) {
            return;
        }
        if (!\in_array($request->getClientIp(), ['127.0.0.1', '::1'], true)) {
            throw new AccessDeniedHttpException($screen.' n\'est accessible que depuis le poste (127.0.0.1). Voir RNM_UPDATE_ALLOW_REMOTE.');
        }
    }
}
