<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote;

/**
 * Convertit le texte éditorial saisi (texte brut avec une syntaxe légère) en
 * XHTML sûr, valide à la fois pour l'aperçu web et le format de stockage Confluence.
 *
 * Syntaxe prise en charge :
 * - ligne vide : nouveau paragraphe ; retour à la ligne simple : <br/> ;
 * - « - » ou « * » en début de ligne : liste à puces ; « 1. » : liste numérotée ;
 * - **gras**, `code` ; les URL http(s) deviennent des liens.
 *
 * Tout le texte est échappé AVANT transformation : aucune balise saisie n'est interprétée.
 */
final class TextFormatter
{
    private const BULLET = '/^\s*[-*•]\s+(.*)$/u';
    private const NUMBERED = '/^\s*\d+[.)]\s+(.*)$/u';
    private const URL = '#(https?://[^\s<>"\'`]+[^\s<>"\'`.,;:!?)\]])#u';

    public function toHtml(?string $text): string
    {
        if (null === $text || '' === trim($text)) {
            return '';
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $blocks = preg_split("/\n\s*\n/u", trim($text)) ?: [];

        $html = '';
        foreach ($blocks as $block) {
            $html .= $this->block($block);
        }

        return $html;
    }

    /** Variante sur une ligne (titres, cellules) : pas de paragraphe ni de liste. */
    public function inline(?string $text): string
    {
        if (null === $text) {
            return '';
        }

        return $this->inlineMarkup(trim(str_replace(["\r\n", "\r", "\n"], ' ', $text)));
    }

    private function block(string $block): string
    {
        $html = '';
        $paragraph = [];
        $listType = null;
        $items = [];

        $flushParagraph = function () use (&$paragraph, &$html): void {
            if ([] !== $paragraph) {
                $html .= '<p>' . implode('<br />', array_map($this->inlineMarkup(...), $paragraph)) . '</p>';
                $paragraph = [];
            }
        };
        $flushList = function () use (&$listType, &$items, &$html): void {
            if (null !== $listType && [] !== $items) {
                $html .= '<' . $listType . '>';
                foreach ($items as $item) {
                    $html .= '<li>' . $this->inlineMarkup($item) . '</li>';
                }
                $html .= '</' . $listType . '>';
            }
            $listType = null;
            $items = [];
        };

        foreach (explode("\n", $block) as $line) {
            if (1 === preg_match(self::BULLET, $line, $m)) {
                $flushParagraph();
                if ('ul' !== $listType) {
                    $flushList();
                    $listType = 'ul';
                }
                $items[] = $m[1];
            } elseif (1 === preg_match(self::NUMBERED, $line, $m)) {
                $flushParagraph();
                if ('ol' !== $listType) {
                    $flushList();
                    $listType = 'ol';
                }
                $items[] = $m[1];
            } elseif (null !== $listType && 1 === preg_match('/^\s{2,}\S/u', $line)) {
                // Ligne indentée : continuation de l'élément de liste précédent.
                $items[\count($items) - 1] .= ' ' . trim($line);
            } else {
                $flushList();
                if ('' !== trim($line)) {
                    $paragraph[] = trim($line);
                }
            }
        }
        $flushList();
        $flushParagraph();

        return $html;
    }

    private function inlineMarkup(string $text): string
    {
        $out = '';
        // 1. Segments `code` (échappés, sans autre transformation).
        foreach (preg_split('/(`[^`]+`)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text] as $part) {
            if (\strlen($part) > 2 && str_starts_with($part, '`') && str_ends_with($part, '`')) {
                $out .= '<code>' . self::escape(substr($part, 1, -1)) . '</code>';
                continue;
            }
            // 2. URL détectées sur le texte brut, puis échappées.
            foreach (preg_split(self::URL, $part, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$part] as $piece) {
                if (1 === preg_match('#^https?://#u', $piece)) {
                    $url = self::escape($piece);
                    $out .= sprintf('<a href="%s">%s</a>', $url, $url);
                    continue;
                }
                // 3. Gras.
                $out .= (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', self::escape($piece));
            }
        }

        return $out;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }
}
