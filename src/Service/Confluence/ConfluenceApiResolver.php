<?php

declare(strict_types=1);

namespace App\Service\Confluence;

use App\Entity\ExternalConnection;
use App\Service\Http\ExternalApiException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Sélectionne l'adaptateur Confluence (Cloud v2 ou Server/DC v1) d'une connexion.
 */
class ConfluenceApiResolver
{
    /** @param iterable<ConfluenceApiInterface> $apis */
    public function __construct(
        #[AutowireIterator(ConfluenceApiInterface::class)]
        private readonly iterable $apis,
    ) {
    }

    public function for(ExternalConnection $connection): ConfluenceApiInterface
    {
        foreach ($this->apis as $api) {
            if ($api->supports($connection)) {
                return $api;
            }
        }

        throw ExternalApiException::configuration(sprintf('La connexion « %s » n\'est pas une connexion Confluence.', $connection->getName()));
    }
}
