<?php

declare(strict_types=1);

namespace App\Service\Confluence\Dto;

final class PagePublishResult
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    /** Page existante réutilisée sans modification. */
    public const UNCHANGED = 'unchanged';
    /** Page existante déplacée sous un autre parent (contenu conservé). */
    public const MOVED = 'moved';

    public function __construct(
        public readonly ConfluencePage $page,
        public readonly string $action,
    ) {
    }
}
