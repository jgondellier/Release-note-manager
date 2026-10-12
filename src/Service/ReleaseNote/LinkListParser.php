<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote;

/**
 * Analyse une liste de liens saisie ligne par ligne : « Libellé | https://… » ou « https://… ».
 * Seules les URL http(s) valides sont conservées (pas de javascript:, data:, etc.).
 */
final class LinkListParser
{
    /** @return list<array{label: string, url: string}> */
    public function parse(?string $text): array
    {
        if (null === $text || '' === trim($text)) {
            return [];
        }
        $links = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            $label = null;
            $url = $line;
            if (str_contains($line, '|')) {
                [$label, $url] = array_map('trim', explode('|', $line, 2));
            }
            if (!self::isSafeUrl($url)) {
                continue;
            }
            $links[] = ['label' => '' !== (string) $label ? (string) $label : $url, 'url' => $url];
        }

        return $links;
    }

    public static function isSafeUrl(string $url): bool
    {
        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return \in_array($scheme, ['http', 'https'], true);
    }
}
