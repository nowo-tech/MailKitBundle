<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph\Inbound;

use Nowo\MailKitBundle\Graph\Inbound\InboundHtmlSanitizer;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function strlen;

final class InboundHtmlSanitizerTest extends TestCase
{
    public function testDangerousMarkupIsRemoved(): void
    {
        $html = (new InboundHtmlSanitizer())->sanitize(
            '<p onclick="steal()" style="background:url(https://t.example/x)">Hi <b>there</b></p>'
            . '<script>alert(1)</script><style>p{}</style><iframe src="https://evil.example"></iframe>'
            . '<form action="https://evil.example"><input name="password"></form>'
            . '<a href="javascript:alert(1)">js</a> <a href="https://ok.example/page">ok</a> <a href="/relative">rel</a>'
            . '<img src="cid:plan@client"> <img src=\'cid:logo.png\'> <img src="cid:bad id"> <img src="https://tracker.example/p.gif">'
            . '<svg><script>alert(2)</script></svg>',
        );
        foreach (['onclick', 'style=', '<script', 'alert', '<iframe', '<form', '<input', 'javascript:', 'tracker.example', 'evil.example', '/relative', '<svg'] as $needle) {
            self::assertStringNotContainsString($needle, $html, $needle);
        }
        self::assertStringContainsString('<b>there</b>', $html);
        self::assertStringContainsString('href="https://ok.example/page" rel="noopener noreferrer nofollow"', $html);
        self::assertStringContainsString('<img src="cid:plan@client" />', $html);
        self::assertStringContainsString('<img src="cid:logo.png" />', $html);
        self::assertStringNotContainsString('bad id', $html);
    }

    public function testRemoteImagesOnlyWhenAllowed(): void
    {
        $html = (new InboundHtmlSanitizer(true))->sanitize('<img src="https://cdn.example/logo.png"><img src="cid:a@b">');
        self::assertStringContainsString('https://cdn.example/logo.png', $html);
        self::assertStringContainsString('<img src="cid:a@b" />', $html);
    }

    public function testInputIsCutAndTextDerived(): void
    {
        $sanitizer = new InboundHtmlSanitizer(false, 2048);
        self::assertLessThanOrEqual(4096, strlen($sanitizer->sanitize('<p>' . str_repeat('a', 10_000) . '</p>')));

        $text = $sanitizer->toText("<p>Hello&nbsp;&amp; welcome</p><div>Line   two<br>three</div>\n\n\n\n<ul><li>x</li></ul>");
        self::assertSame("Hello & welcome\nLine two\nthree\n\nx", $text);
    }

    public function testForgedPlaceholderIsDropped(): void
    {
        $html = (new InboundHtmlSanitizer())->sanitize('<img src="https://cid.mail-kit.invalid/%20bad">');
        self::assertStringNotContainsString('cid.mail-kit.invalid', $html);
        self::assertStringNotContainsString('cid:', $html);
    }
}
