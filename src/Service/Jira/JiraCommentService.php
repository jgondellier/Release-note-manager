<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Entity\ExternalConnection;
use App\Service\Http\AtlassianApiClient;
use App\Service\Http\ExternalApiException;
use App\Service\Jira\Dto\JiraComment;

/**
 * Lecture paginée des commentaires d'un ticket (Cloud : API v3 / ADF ; Server : API v2 / texte).
 */
class JiraCommentService
{
    private const PAGE_SIZE = 100;
    private const MAX_COMMENTS = 1000;

    public function __construct(
        private readonly AtlassianApiClient $client,
        private readonly AdfTextExtractor $adf,
    ) {
    }

    /** @return list<JiraComment> du plus ancien au plus récent */
    public function getComments(ExternalConnection $connection, string $issueKey): array
    {
        JiraApi::assertJira($connection);
        if (!JiraJqlBuilder::isValidIssueKey($issueKey)) {
            throw new \InvalidArgumentException(sprintf('Clé de ticket invalide : %s', $issueKey));
        }

        $comments = [];
        $startAt = 0;
        do {
            $data = $this->client->get($connection, sprintf('%s/issue/%s/comment', JiraApi::prefix($connection), rawurlencode($issueKey)), [
                'startAt' => $startAt,
                'maxResults' => self::PAGE_SIZE,
                'orderBy' => 'created',
            ]);
            if (!isset($data['comments']) || !\is_array($data['comments'])) {
                throw new ExternalApiException(sprintf('Réponse Jira invalide pour les commentaires de %s.', $issueKey), ExternalApiException::INVALID_RESPONSE);
            }
            $received = 0;
            foreach ($data['comments'] as $raw) {
                if (!\is_array($raw)) {
                    continue;
                }
                ++$received;
                $comments[] = JiraComment::fromApi($raw, $this->adf);
            }
            $startAt += $received;
            $total = (int) ($data['total'] ?? 0);
        } while ($received > 0 && $startAt < $total && $startAt < self::MAX_COMMENTS);

        usort($comments, static fn (JiraComment $a, JiraComment $b) => ($a->createdAt?->getTimestamp() ?? 0) <=> ($b->createdAt?->getTimestamp() ?? 0));

        return $comments;
    }

    /**
     * Commentaires commençant par le préfixe (insensible à la casse et aux espaces
     * autour du « : »), préfixe retiré.
     *
     * @param list<JiraComment> $comments
     *
     * @return list<JiraComment>
     */
    public static function filterByPrefix(array $comments, string $prefix): array
    {
        $pattern = self::prefixPattern($prefix);
        $result = [];
        foreach ($comments as $comment) {
            $body = ltrim($comment->body);
            if (1 === preg_match($pattern, $body, $m)) {
                $text = trim(substr($body, \strlen($m[0])));
                $result[] = new JiraComment($comment->id, $comment->author, $comment->createdAt, $text);
            }
        }

        return $result;
    }

    private static function prefixPattern(string $prefix): string
    {
        $prefix = trim($prefix);
        // « TEST : », « TEST: », « Test :» sont équivalents ; éventuel formatage Jira (*TEST :*) toléré.
        $parts = array_map(static fn (string $p) => preg_quote(trim($p), '/'), explode(':', $prefix, 2));
        $core = 2 === \count($parts) ? $parts[0] . '\s*:' . ('' !== $parts[1] ? '\s*' . $parts[1] : '') : $parts[0];

        return '/^[*_]?\s*' . $core . '[*_]?\s*/iu';
    }
}
