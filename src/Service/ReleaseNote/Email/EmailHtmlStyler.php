<?php

declare(strict_types=1);

namespace App\Service\ReleaseNote\Email;

/**
 * Ajoute des styles en ligne au XHTML produit par TextFormatter (p, listes, liens, code) :
 * les clients de messagerie, Outlook en tête, ignorent les feuilles de style.
 * L'entrée est déjà sûre (texte échappé puis mis en forme) : seuls les attributs style sont ajoutés.
 */
final class EmailHtmlStyler
{
    public const LINK_COLOR = '#0B5CAD';

    private const STYLES = [
        'p' => 'margin:0 0 10px 0;',
        'ul' => 'margin:0 0 10px 0;padding-left:22px;',
        'ol' => 'margin:0 0 10px 0;padding-left:22px;',
        'li' => 'margin:0 0 4px 0;',
        'code' => "font-family:Consolas,'Courier New',monospace;font-size:13px;background-color:#F1F3F6;padding:1px 4px;",
    ];

    public function style(string $html): string
    {
        foreach (self::STYLES as $tag => $style) {
            $html = (string) preg_replace('#<' . $tag . '>#', '<' . $tag . ' style="' . $style . '">', $html);
        }

        return (string) preg_replace('#<a href=#', '<a style="color:' . self::LINK_COLOR . ';text-decoration:underline;" href=', $html);
    }
}
