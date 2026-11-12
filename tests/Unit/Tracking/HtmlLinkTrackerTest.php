<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Tracking;

use Closure;
use Nowo\MailKitBundle\Tracking\HtmlLinkTracker;
use PHPUnit\Framework\TestCase;

use function ini_get;
use function ini_set;
use function str_repeat;

final class HtmlLinkTrackerTest extends TestCase
{
    /** @var list<array{0: string, 1: string|null, 2: int}> */
    private array $calls = [];

    public function testRewritesAnchorsAreasAndVmlButtonsAndKeepsEverythingElse(): void
    {
        $html = <<<'HTML'
            <html><head><style>a[href="https://app.example.com/style"] { color: red }</style>
            <script>var x = '<a href="https://app.example.com/script">x</a>';</script></head>
            <body>
            <!-- <a href="https://app.example.com/commented">old</a> -->
            <a class="btn" href="https://app.example.com/issues/1?a=1&amp;b=2">View <b>issue</b></a>
            <A HREF='https://app.example.com/single'>Single</A>
            <a href=https://app.example.com/unquoted>Unquoted</a>
            <a href="mailto:help@example.com">Mail</a> <a href="tel:+34600000000">Call</a>
            <a href="#top">Top</a> <a href="/relative">Rel</a> <a name="anchor">no href</a>
            <a data-href="https://app.example.com/data" title="t">Data attr only</a>
            <a href="https://app.example.com/off" data-mail-kit-track="off">Off</a>
            <map><area shape="rect" href="https://app.example.com/area" alt="Area"></map>
            <!--[if mso]><v:roundrect href="https://app.example.com/vml" arcsize="10%"><center>Btn</center></v:roundrect><![endif]-->
            <a href="https://app.example.com/a>b" title="Gt">Gt</a>
            </body></html>
            HTML;

        $result = (new HtmlLinkTracker())->rewriteHtml($html, $this->recorder());

        self::assertSame([
            ['https://app.example.com/issues/1?a=1&b=2', 'View issue', 1],
            ['https://app.example.com/single', 'Single', 2],
            ['https://app.example.com/unquoted', 'Unquoted', 3],
            ['https://app.example.com/area', 'Area', 5],
            ['https://app.example.com/vml', null, 6],
            ['https://app.example.com/a>b', 'Gt', 7],
        ], $this->calls);
        $out = $result['html'];
        self::assertStringContainsString('<a class="btn" href="https://t.example/1?x=1&amp;y=$1\\">View <b>issue</b></a>', $out);
        self::assertStringContainsString('<A HREF="https://t.example/2?x=1&amp;y=$1\\">Single</A>', $out);
        self::assertStringContainsString('<a href="https://t.example/3?x=1&amp;y=$1\\">Unquoted</a>', $out);
        self::assertStringContainsString('<v:roundrect href="https://t.example/6?x=1&amp;y=$1\\" arcsize="10%">', $out);
        foreach (['/style"]', '/script">', '/commented">', 'mailto:help@example.com', 'tel:+34600000000', '"#top"', '"/relative"', 'data-href="https://app.example.com/data"', 'href="https://app.example.com/off" data-mail-kit-track="off"'] as $kept) {
            self::assertStringContainsString($kept, $out);
        }
        self::assertSame('https://t.example/1?x=1&y=$1\\', $result['map']['https://app.example.com/issues/1?a=1&b=2']);
        self::assertCount(6, $result['map']);
    }

    public function testMalformedHtmlNeverBreaksTheBody(): void
    {
        $tracker = new HtmlLinkTracker();
        $html    = '<p>Broken <a href="https://app.example.com/open">never closed <a href="https://app.example.com/b>quote <a href=\'https://app.example.com/c\'>C</a> <!-- unclosed comment <a href="https://app.example.com/d">D</a>';
        $result  = $tracker->rewriteHtml($html, $this->recorder());
        self::assertSame('https://app.example.com/open', $this->calls[0][0]);
        self::assertStringContainsString('never closed', $result['html']);
        self::assertStringContainsString('<!-- unclosed comment', $result['html']);

        self::assertSame(['html' => '', 'map' => []], $tracker->rewriteHtml('', $this->recorder()));
        self::assertSame(['html' => 'plain text https://app.example.com', 'map' => []], $tracker->rewriteHtml('plain text https://app.example.com', $this->recorder()));
    }

    public function testLabelsComeFromAriaTextImageAltOrTitle(): void
    {
        $html = '<a href="https://x.example/1" aria-label="Aria &amp; co">Text</a>'
            . '<a href="https://x.example/2">  multi
                line   text  </a>'
            . '<a href="https://x.example/3"><img src="cid:logo" alt="Logo alt"></a>'
            . '<a href="https://x.example/4" title="Title only"><img src="cid:x"></a>'
            . '<a href="https://x.example/5"><img src="cid:y"></a>'
            . '<a href="https://x.example/6">' . str_repeat('é', 150) . '</a>'
            . '<a href="https://x.example/7" title="Unclosed">tail';
        (new HtmlLinkTracker())->rewriteHtml($html, $this->recorder());

        self::assertSame(['Aria & co', 'multi line text', 'Logo alt', 'Title only', null, str_repeat('é', 100), 'Unclosed'], [$this->calls[0][1], $this->calls[1][1], $this->calls[2][1], $this->calls[3][1], $this->calls[4][1], $this->calls[5][1], $this->calls[6][1]]);
    }

    public function testKeepsLinksTheRewriterDeclinesAndStopsAtTheLimit(): void
    {
        $html   = '<a href="https://x.example/1">1</a><a href="https://x.example/2">2</a><a href="https://x.example/3">3</a><a href="https://x.example/4">4</a><a href="https://x.example/1">again</a>';
        $result = (new HtmlLinkTracker(2))->rewriteHtml($html, static fn (string $url, ?string $label, int $position): ?string => $position === 2 ? null : 'https://t.example/' . $position);

        self::assertSame('<a href="https://t.example/1">1</a><a href="https://x.example/2">2</a><a href="https://t.example/3">3</a><a href="https://x.example/4">4</a><a href="https://x.example/1">again</a>', $result['html']);
        self::assertSame(['https://x.example/1' => 'https://t.example/1', 'https://x.example/3' => 'https://t.example/3'], $result['map']);
    }

    public function testTheTextPartGetsTheSameTrackingUrls(): void
    {
        $tracker = new HtmlLinkTracker();
        $map     = ['https://app.example.com/issues/1' => 'https://app.example.com/s/abc-1'];
        $text    = "View it: https://app.example.com/issues/1.\n(https://app.example.com/issues/1) https://app.example.com/issues/12 https://app.example.com/reset?token=x mailto:a@example.com";

        self::assertSame("View it: https://app.example.com/s/abc-1.\n(https://app.example.com/s/abc-1) https://app.example.com/issues/12 https://app.example.com/reset?token=x mailto:a@example.com", $tracker->rewriteText($text, $map));
        self::assertSame($text, $tracker->rewriteText($text, []));
    }

    public function testThePixelGoesBeforeTheLastBodyOrAtTheEnd(): void
    {
        $tracker = new HtmlLinkTracker();
        $img     = '<img src="https://app.example.com/e/o/t.gif?a=1&amp;b=2" width="1" height="1" alt="" border="0" style="display:block;width:1px;height:1px;border:0;margin:0;padding:0;" />';

        self::assertSame('<html><body><p>Hi</p>' . $img . '</BODY></html>', $tracker->injectPixel('<html><body><p>Hi</p></BODY></html>', 'https://app.example.com/e/o/t.gif?a=1&b=2'));
        self::assertSame('<p>Fragment</p>' . $img, $tracker->injectPixel('<p>Fragment</p>', 'https://app.example.com/e/o/t.gif?a=1&b=2'));
    }

    public function testAPcreFailureKeepsTheBody(): void
    {
        $limit = (string) ini_get('pcre.backtrack_limit');
        $jit   = (string) ini_get('pcre.jit');
        ini_set('pcre.backtrack_limit', '1');
        ini_set('pcre.jit', '0');
        try {
            $html   = '<a href="https://x.example/1">1</a><!-- ' . str_repeat('x', 5000) . ' -->';
            $result = (new HtmlLinkTracker())->rewriteHtml($html, $this->recorder());
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
            ini_set('pcre.jit', $jit);
        }
        self::assertSame(['html' => $html, 'map' => []], $result);
    }

    /**
     * @return Closure(string, string|null, int): string
     */
    private function recorder(): Closure
    {
        $this->calls = [];

        return function (string $url, ?string $label, int $position): string {
            $this->calls[] = [$url, $label, $position];

            // `&` must be escaped in the attribute, `$1` and `\` must reach it literally.
            return 'https://t.example/' . $position . '?x=1&y=$1\\';
        };
    }
}
