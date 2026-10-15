<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PublicationTarget;
use App\Repository\PublicationContainerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Page Confluence de regroupement d'une release sur une instance (Cloud ou Server) :
 * les pages des trois formats sont publiées sous cette page.
 */
#[ORM\Entity(repositoryClass: PublicationContainerRepository::class)]
#[ORM\Table(name: 'publication_container')]
#[ORM\UniqueConstraint(name: 'uniq_container_release_target', columns: ['release_id', 'target'])]
#[ORM\HasLifecycleCallbacks]
class PublicationContainer
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'containers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Release $release = null;

    #[ORM\Column(length: 20, enumType: PublicationTarget::class)]
    private PublicationTarget $target;

    #[ORM\Column(length: 50)]
    private string $confluencePageId;

    #[ORM\Column(length: 500)]
    private string $confluencePageUrl;

    #[ORM\Column(length: 255)]
    private string $pageTitle;

    #[ORM\Column]
    private int $confluencePageVersion;

    /** Empreinte du contenu publié : la page n'est mise à jour que si elle change. */
    #[ORM\Column(length: 64)]
    private string $contentHash;

    public function __construct(PublicationTarget $target, string $pageId, string $pageUrl, string $pageTitle, int $version, string $contentHash)
    {
        $this->target = $target;
        $this->confluencePageId = $pageId;
        $this->confluencePageUrl = $pageUrl;
        $this->pageTitle = $pageTitle;
        $this->confluencePageVersion = $version;
        $this->contentHash = $contentHash;
    }

    public function update(string $pageId, string $pageUrl, string $pageTitle, int $version, string $contentHash): static
    {
        $this->confluencePageId = $pageId;
        $this->confluencePageUrl = $pageUrl;
        $this->pageTitle = $pageTitle;
        $this->confluencePageVersion = $version;
        $this->contentHash = $contentHash;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRelease(): ?Release
    {
        return $this->release;
    }

    public function setRelease(?Release $release): static
    {
        $this->release = $release;

        return $this;
    }

    public function getTarget(): PublicationTarget
    {
        return $this->target;
    }

    public function getConfluencePageId(): string
    {
        return $this->confluencePageId;
    }

    public function getConfluencePageUrl(): string
    {
        return $this->confluencePageUrl;
    }

    public function getPageTitle(): string
    {
        return $this->pageTitle;
    }

    public function getConfluencePageVersion(): int
    {
        return $this->confluencePageVersion;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }
}
