<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Brand;

use Nowo\MailKitBundle\Brand\BrandedEmailHelper;
use Nowo\MailKitBundle\Brand\BrandedEmailText;
use Nowo\MailKitBundle\Twig\MailKitBrandTwigExtension;
use PHPUnit\Framework\TestCase;
use Twig\Node\Node;

use function str_repeat;

final class BrandedEmailTextTest extends TestCase
{
    public function testSafeNames(): void
    {
        foreach (['Acme', ' Acme ', 'Café Größe', 'Mañana, S.L.', '<b>"Acme" & Co</b>', str_repeat('é', 80), "فارسی\u{200C}ها"] as $name) {
            self::assertTrue(BrandedEmailText::isSafeName($name), $name);
        }
        foreach ([null, 12, '', '   ', "\u{200B}", "\u{00A0}", "Ac\u{202E}me", "a\u{2028}b", "a\nb", "a\rb", "a\tb", "a\u{0085}b", "\xff", str_repeat('x', 81)] as $name) {
            self::assertFalse(BrandedEmailText::isSafeName($name), (string) json_encode($name));
        }
    }

    public function testPlainText(): void
    {
        self::assertSame('Acme <b> & Co', BrandedEmailText::plain(" Acme\r\n <b> & Co\u{202E} "));
        self::assertSame('ab', BrandedEmailText::plain("a\u{2029}\u{2066}b"));
        self::assertSame('', BrandedEmailText::plain("\xff"));
        self::assertSame('', BrandedEmailText::plain(null));
    }

    public function testTwigExtension(): void
    {
        $extension = new MailKitBrandTwigExtension(new BrandedEmailHelper('Acme'));

        $functions = $extension->getFunctions();
        self::assertCount(1, $functions);
        self::assertSame('nowo_mail_kit_brand', $functions[0]->getName());
        self::assertSame([], $functions[0]->getSafe(new Node()) ?? []);
        $callable = $functions[0]->getCallable();
        self::assertIsCallable($callable);
        self::assertSame('Acme', $callable()['name']);

        $filters = $extension->getFilters();
        self::assertCount(1, $filters);
        self::assertSame('nowo_mail_kit_plain_text', $filters[0]->getName());
        $filter = $filters[0]->getCallable();
        self::assertIsCallable($filter);
        self::assertSame('ab', $filter("a\nb"));
    }
}
