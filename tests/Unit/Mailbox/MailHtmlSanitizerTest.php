<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Mailbox;

use Nowo\MailKitBundle\Mailbox\Html\MailHtmlSanitizer;
use Nowo\MailKitBundle\Mailbox\Html\RemoteImages;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function strlen;

/**
 * 2.5.0 mail HTML sanitiser (design section 11, Q-MB-8 / Q-MB-9): `blocked` keeps a remote image's URL only in
 * `data-mail-kit-remote-src` (re-validated), removes other remote media, keeps `cid:` images; no script, event
 * handler or style survives in any mode.
 */
final class MailHtmlSanitizerTest extends TestCase
{
    public function testBlockedModeMovesRemoteImagesToTheDataAttribute(): void
    {
        $sanitizer = new MailHtmlSanitizer(RemoteImages::Blocked);
        $html      = $sanitizer->sanitize(
            '<p style="color:red" onclick="x()">Hi<script>alert(1)</script></p>'
            . '<style>p{}</style>'
            . '<img alt="logo" src="https://cdn.example.net/logo.png?a=1&amp;b=2">'
            . '<img src="https://user:pw@cdn.example.net/x.png">'
            . '<img src="http://cdn.example.net/plain.png">'
            . '<img src="https://cdn.example.net/' . str_repeat('a', MailHtmlSanitizer::MAX_REMOTE_URL) . '.png">'
            . '<img src="cid:part1@example.net">'
            . '<img srcset="https://cdn.example.net/a.png 1x" src="https://cdn.example.net/b.png">'
            . '<video poster="https://cdn.example.net/p.png" src="https://cdn.example.net/v.mp4"></video>'
            . '<table background="https://cdn.example.net/bg.png"><tr><td>t</td></tr></table>'
            . '<a href="https://example.org/x">link</a>',
        );

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('style', $html);
        self::assertStringNotContainsString(' src="https://', $html);
        self::assertStringNotContainsString('srcset', $html);
        self::assertStringNotContainsString('poster', $html);
        self::assertStringNotContainsString('background', $html);
        self::assertStringNotContainsString('<video', $html);
        self::assertStringNotContainsString('user:pw', $html);
        self::assertStringNotContainsString('http://cdn', $html);
        self::assertStringContainsString('<img alt="logo" data-mail-kit-remote-src="https://cdn.example.net/logo.png?a=1&amp;b=2"', $html);
        self::assertStringContainsString('data-mail-kit-remote-src="https://cdn.example.net/b.png"', $html);
        self::assertStringContainsString('src="cid:part1@example.net"', $html);
        self::assertStringContainsString('rel="noopener noreferrer nofollow"', $html);
        self::assertSame(2, MailHtmlSanitizer::blockedImages($html));
    }

    public function testASenderCannotPlantTheDataAttribute(): void
    {
        $html = (new MailHtmlSanitizer(RemoteImages::Blocked))->sanitize('<img data-mail-kit-remote-src="javascript:alert(1)" src="cid:a@b">');

        self::assertStringNotContainsString('javascript', $html);
        self::assertSame(0, MailHtmlSanitizer::blockedImages($html));
    }

    public function testNoOtherRemoteMediaVectorSurvivesUnlessAllowed(): void
    {
        $source = '<img src="cid:a@b" srcset="https://t.example/1.png 1x" dynsrc="https://t.example/2" longdesc="https://t.example/3" lowsrc="https://t.example/4" style="background:url(https://t.example/5)">'
            . '<picture><source srcset="https://t.example/6.png"><img src="cid:c@d"></picture>'
            . '<video poster="https://t.example/7.png"><track src="https://t.example/8.vtt"></video>'
            . '<table background="https://t.example/9.png"><tr><td>x</td></tr></table>'
            . '<link rel="stylesheet" href="https://t.example/10.css"><style>p{background:url(https://t.example/11)}</style>';
        foreach ([RemoteImages::Dropped, RemoteImages::Blocked] as $mode) {
            $html = (new MailHtmlSanitizer($mode))->sanitize($source);
            self::assertStringNotContainsString('t.example', $html, $mode->value);
            self::assertStringContainsString('src="cid:a@b"', $html, $mode->value);
        }
    }

    public function testDroppedAndAllowedModes(): void
    {
        $source  = '<img src="https://cdn.example.net/a.png"><img src="cid:a@b">';
        $dropped = (new MailHtmlSanitizer(RemoteImages::Dropped))->sanitize($source);
        self::assertStringNotContainsString('cdn.example.net', $dropped);
        self::assertStringContainsString('src="cid:a@b"', $dropped);

        $allowed = (new MailHtmlSanitizer(RemoteImages::Allowed))->sanitize($source);
        self::assertStringContainsString('src="https://cdn.example.net/a.png"', $allowed);
        self::assertSame(0, MailHtmlSanitizer::blockedImages($allowed));
    }

    public function testInputIsCutAndTextDerived(): void
    {
        $sanitizer = new MailHtmlSanitizer(RemoteImages::Blocked, 2048);
        self::assertLessThanOrEqual(2100, strlen($sanitizer->sanitize('<p>' . str_repeat('x', 5000) . '</p>')));
        self::assertSame("Hello\nworld", $sanitizer->toText('<p>Hello</p><p>world</p>'));
    }
}
