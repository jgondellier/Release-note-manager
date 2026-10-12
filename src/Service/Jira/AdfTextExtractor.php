<?php

declare(strict_types=1);

namespace App\Service\Jira;

/**
 * Convertit un document ADF (Atlassian Document Format, Jira Cloud API v3) en texte brut lisible.
 */
final class AdfTextExtractor
{
    /** @param array<string, mixed> $node */
    public function extract(array $node): string
    {
        $text = $this->walk($node);
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /** @param array<string, mixed> $node */
    private function walk(array $node, int $depth = 0): string
    {
        if ($depth > 50) {
            return '';
        }
        $type = (string) ($node['type'] ?? '');
        $children = '';
        foreach ((array) ($node['content'] ?? []) as $child) {
            if (\is_array($child)) {
                $children .= $this->walk($child, $depth + 1);
            }
        }

        return match ($type) {
            'text' => (string) ($node['text'] ?? ''),
            'hardBreak' => "\n",
            'paragraph', 'heading' => $children . "\n\n",
            'listItem' => '- ' . trim($children) . "\n",
            'bulletList', 'orderedList' => $children . "\n",
            'codeBlock' => $children . "\n\n",
            'mention' => (string) ($node['attrs']['text'] ?? ''),
            'emoji' => (string) ($node['attrs']['text'] ?? $node['attrs']['shortName'] ?? ''),
            'inlineCard', 'blockCard' => (string) ($node['attrs']['url'] ?? ''),
            'tableCell', 'tableHeader' => trim($children) . ' | ',
            'tableRow' => $children . "\n",
            default => $children,
        };
    }
}
