<?php

declare(strict_types=1);

namespace App\Service\Confluence;

use App\Entity\ExternalConnection;
use App\Service\Http\ExternalApiException;

class ConfluenceConnectionTester
{
    public function __construct(private readonly ConfluenceApiResolver $resolver)
    {
    }

    public function test(ExternalConnection $connection): string
    {
        return $this->resolver->for($connection)->testConnection($connection);
    }

    public function checkSpace(ExternalConnection $connection, string $spaceKey): string
    {
        $name = $this->resolver->for($connection)->checkSpace($connection, $spaceKey);

        return sprintf('Espace %s accessible (« %s »).', $spaceKey, $name);
    }

    public function checkParentPage(ExternalConnection $connection, string $pageId): string
    {
        $page = $this->resolver->for($connection)->getPage($connection, $pageId);
        if (null === $page) {
            throw new ExternalApiException(sprintf('Page parente %s introuvable.', $pageId), ExternalApiException::NOT_FOUND, 404);
        }

        return sprintf('Page parente « %s » trouvée.', $page->title);
    }
}
