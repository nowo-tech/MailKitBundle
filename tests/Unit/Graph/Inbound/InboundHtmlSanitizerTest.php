<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph\Inbound;

use Nowo\MailKitBundle\Graph\Inbound\InboundHtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Audit ZAT-L4: without remote images, no URL-bearing attribute or element survives that the installed
     * html-sanitizer might not check (srcset was kept without a URL check before 7.4.23 / 8.1.11).
     *
     * @return iterable<string, array{0: string}>
     */
    public static function remoteImageVectors(): iterable
    {
        $url = 'https://tracker.example/p.png';

        yield 'img srcset' => ['<img src="cid:a@b" srcset="' . $url . ' 1x, ' . $url . ' 2x">'];
        yield 'picture source srcset' => ['<picture><source srcset="' . $url . '" type="image/png"><img src="cid:a@b"></picture>'];
        yield 'video source src' => ['<video><source src="' . $url . '"></video>'];
        yield 'track src' => ['<video><track src="' . $url . '"></video>'];
        yield 'video poster' => ['<video poster="' . $url . '"></video>'];
        yield 'table background' => ['<table background="' . $url . '"><tr><td background="' . $url . '">x</td></tr></table>'];
        yield 'body background' => ['<body background="' . $url . '"><p>x</p></body>'];
        yield 'inline style url()' => ['<p style="background-image:url(' . $url . ')">x</p><div style="background: url(\'' . $url . '\')">y</div>'];
        yield 'style element' => ['<style>p{background:url(' . $url . ')}</style><p>x</p>'];
        yield 'link stylesheet' => ['<link rel="stylesheet" href="' . $url . '"><p>x</p>'];
        yield 'link imagesrcset' => ['<link rel="preload" as="image" imagesrcset="' . $url . ' 1x"><p>x</p>'];
        yield 'img lowsrc' => ['<img src="cid:a@b" lowsrc="' . $url . '">'];
        yield 'img dynsrc' => ['<img src="cid:a@b" dynsrc="' . $url . '">'];
        yield 'img longdesc' => ['<img src="cid:a@b" longdesc="' . $url . '">'];
    }

    #[DataProvider('remoteImageVectors')]
    public function testDroppedModeStripsEveryRemoteImageVector(string $input): void
    {
        $html = (new InboundHtmlSanitizer())->sanitize($input);

        self::assertStringNotContainsString('tracker.example', $html);
        foreach (['srcset', 'lowsrc', 'dynsrc', 'poster', 'background', 'longdesc', 'style', '<source', '<track', '<link'] as $needle) {
            self::assertStringNotContainsString($needle, $html, $needle);
        }
    }

    public function testDroppedModeKeepsInlineImagesInsidePicture(): void
    {
        $html = (new InboundHtmlSanitizer())->sanitize('<picture><source srcset="https://tracker.example/p.png"><img src="cid:a@b" alt="plan"></picture>');

        self::assertSame('<picture><img src="cid:a@b" alt="plan" /></picture>', $html);
    }

    public function testAllowedModeKeepsSrcset(): void
    {
        $html = (new InboundHtmlSanitizer(true))->sanitize('<img src="https://cdn.example/a.png" srcset="https://cdn.example/a2.png 2x">');

        self::assertStringContainsString('srcset="https://cdn.example/a2.png 2x"', $html);
    }
}
