<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\ReleaseNote\LinkListParser;
use App\Service\ReleaseNote\TextFormatter;
use PHPUnit\Framework\TestCase;

final class TextFormatterTest extends TestCase
{
    public function testLightweightSyntaxIsConvertedToXhtml(): void
    {
        $html = (new TextFormatter())->toHtml("Intro **important**\nsuite\n\n- un\n- deux `x<y`\n\n1. a\n2. b");
        self::assertSame('<p>Intro <strong>important</strong><br />suite</p><ul><li>un</li><li>deux <code>x&lt;y</code></li></ul><ol><li>a</li><li>b</li></ol>', $html);
    }

    public function testHtmlIsAlwaysEscaped(): void
    {
        $html = (new TextFormatter())->toHtml('<script>alert(1)</script> <a href="javascript:x">y</a>');
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<a href="javascript', $html);
    }

    public function testUrlsBecomeLinksWithoutBreakingEntities(): void
    {
        $html = (new TextFormatter())->toHtml("Voir https://ex.com/a?b=1&c=2. Et l'https://x.io/p's");
        self::assertStringContainsString('<a href="https://ex.com/a?b=1&amp;c=2">', $html);
        self::assertStringContainsString('<a href="https://x.io/p">', $html);
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML('<root>' . $html . '</root>'), 'XHTML bien formé');
    }

    public function testLinkParserKeepsOnlyHttpUrls(): void
    {
        $links = (new LinkListParser())->parse("Guide | https://ex.com/guide\njavascript:alert(1)\nhttps://ex.com/brut\nMauvais | ftp://x");
        self::assertSame([
            ['label' => 'Guide', 'url' => 'https://ex.com/guide'],
            ['label' => 'https://ex.com/brut', 'url' => 'https://ex.com/brut'],
        ], $links);
    }
}
