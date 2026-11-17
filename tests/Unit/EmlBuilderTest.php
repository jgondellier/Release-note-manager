<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\ReleaseNote\Email\EmailHtmlStyler;
use App\Service\ReleaseNote\Email\EmlBuilder;
use PHPUnit\Framework\TestCase;

final class EmlBuilderTest extends TestCase
{
    public function testAddressesAreValidatedAndHeaderInjectionIsImpossible(): void
    {
        [$valid, $invalid] = EmlBuilder::parseAddresses("a@acme.fr; b@acme.fr, a@acme.fr\nBcc: pirate@x.io nope");

        self::assertSame(['a@acme.fr', 'b@acme.fr', 'pirate@x.io'], $valid);
        self::assertSame(['Bcc:', 'nope'], $invalid);
    }

    public function testDraftIsAnUnsentMultipartMessageWithEncodedSubject(): void
    {
        $eml = (new EmlBuilder())->build('Finagora 2.4.0 — Nouveautés', '<p>Bonjour <a href="https://x.io/a?b=1&amp;c=2">guide</a></p><ul><li>un</li></ul>', ['a@acme.fr'], ['b@acme.fr']);

        self::assertStringStartsWith("X-Unsent: 1\r\nTo: a@acme.fr\r\nCc: b@acme.fr\r\nSubject: =?UTF-8?B?" . base64_encode('Finagora 2.4.0 — Nouveautés') . "?=\r\n", $eml);
        self::assertStringContainsString('Content-Type: multipart/alternative; boundary="rnm-', $eml);
        preg_match_all('/Content-Transfer-Encoding: base64\r\n\r\n(.+?)\r\n--/s', $eml, $m);
        self::assertCount(2, $m[1]);
        self::assertSame("Bonjour guide (https://x.io/a?b=1&c=2)\n\n- un\n", base64_decode(str_replace("\r\n", '', $m[1][0])));
        self::assertStringContainsString('<a href="https://x.io/a?b=1&amp;c=2">', base64_decode(str_replace("\r\n", '', $m[1][1])));
        foreach (explode("\r\n", $eml) as $line) {
            self::assertLessThanOrEqual(998, \strlen($line), 'Lignes conformes à la RFC 5322');
        }
    }

    public function testStylerAddsInlineStylesOnly(): void
    {
        $html = (new EmailHtmlStyler())->style('<p>a <code>x</code> <a href="https://x.io">l</a></p><ul><li>b</li></ul>');

        self::assertStringContainsString('<p style="margin:0 0 10px 0;">', $html);
        self::assertStringContainsString('<li style="margin:0 0 4px 0;">b</li>', $html);
        self::assertStringContainsString('<a style="color:#0B5CAD;text-decoration:underline;" href="https://x.io">l</a>', $html);
        self::assertStringContainsString('<code style="', $html);
    }
}
