<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote\Email;

/**
 * Fichier .eml « brouillon » : ouvert dans Outlook (en-tête X-Unsent), il s'affiche comme un nouveau message
 * prêt à envoyer, mis en forme, avec objet et destinataires préremplis. Version texte jointe pour les
 * clients qui n'affichent pas le HTML.
 */
final class EmlBuilder
{
    /**
     * @param list<string> $to
     * @param list<string> $cc
     */
    public function build(string $subject, string $html, array $to = [], array $cc = []): string
    {
        $boundary = 'rnm-' . bin2hex(random_bytes(12));
        $headers = ['X-Unsent: 1'];
        if ([] !== $to) {
            $headers[] = 'To: ' . implode(', ', $to);
        }
        if ([] !== $cc) {
            $headers[] = 'Cc: ' . implode(', ', $cc);
        }
        $headers[] = 'Subject: ' . self::encodeHeader($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $parts = [];
        foreach (['text/plain' => self::toText($html), 'text/html' => $html] as $type => $body) {
            $parts[] = '--' . $boundary . "\r\n"
                . 'Content-Type: ' . $type . "; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n";
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . implode('', $parts) . '--' . $boundary . "--\r\n";
    }

    /**
     * Adresses saisies (séparées par virgule, point-virgule ou espace), validées : aucune injection d'en-tête.
     *
     * @return array{0: list<string>, 1: list<string>} [adresses valides, saisies invalides]
     */
    public static function parseAddresses(?string $input): array
    {
        $valid = [];
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', trim((string) $input)) ?: [] as $address) {
            if ('' === $address) {
                continue;
            }
            if (false !== filter_var($address, FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n]/', $address)) {
                $valid[] = $address;
            } else {
                $invalid[] = $address;
            }
        }

        return [array_values(array_unique($valid)), $invalid];
    }

    /** Version texte lisible du HTML (liens conservés entre parenthèses). */
    public static function toText(string $html): string
    {
        $html = (string) preg_replace('#<(style|head|title)[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace_callback('#<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', static function (array $m): string {
            $label = trim(strip_tags($m[2]));
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return $label === $url || '' === $label ? $url : $label . ' (' . $url . ')';
        }, $html);
        $html = (string) preg_replace('#<li[^>]*>#i', "\n- ", $html);
        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = (string) preg_replace('#</(p|div|h[1-6]|tr|table|ul|ol)>#i', "\n\n", $html);
        $html = (string) preg_replace('#</td>#i', '  ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/[ \t\xC2\xA0]+/u", ' ', $text);
        $text = (string) preg_replace("/ *\n */", "\n", $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text)) . "\n";
    }

    private static function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);

        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }
}
