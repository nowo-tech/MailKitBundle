<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Brand;

use Nowo\Brand\Contract\BrandAssetReaderInterface;
use Nowo\Brand\Contract\BrandAssetSlot;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\MailKitBundle\Brand\BrandedEmailHelper;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Integration\Kernel\MailKitTestKernel;
use Nowo\MailKitBundle\Tests\Support\BrandFixtures;
use Nowo\MailKitBundle\Tests\Support\MemoryBrandAssetReader;
use Nowo\MailKitBundle\Tests\Support\StaticBrandProvider;
use Symfony\Bridge\Twig\Mime\BodyRenderer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\AbstractMultipartPart;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\TextPart;
use Twig\Environment;

use function array_push;
use function array_shift;
use function preg_match;

/**
 * Spec 220 S7 end to end: a TemplatedEmail extending the branded layout, passed through BrandedEmailHelper::apply()
 * and sent by the framework mailer (rendered by the BodyRenderer, delivered by the memory transport).
 */
final class BrandedEmailTest extends KernelTestCase
{
    public function testTheLogoIsSentAsACidPartAndTheBrandIsEscaped(): void
    {
        $brand  = BrandFixtures::brand([BrandAssetSlot::LOGO_LIGHT => BrandFixtures::asset(BrandAssetSlot::LOGO_LIGHT)], '<b>"Acme" & Co</b>');
        $kernel = $this->boot(new StaticBrandProvider($brand), new MemoryBrandAssetReader([BrandAssetSlot::LOGO_LIGHT => BrandFixtures::png(200, 50)]));

        $this->send($kernel, $this->helper($kernel)->apply($this->email()));

        $sent = $this->sent($kernel);
        $html = (string) $sent->getHtmlBody();
        $text = (string) $sent->getTextBody();

        self::assertSame(1, preg_match('/<img src="cid:([^"]+)" width="160" height="40" alt="&lt;b&gt;&quot;Acme&quot; &amp; Co&lt;\/b&gt;"/', $this->wire($sent), $m), $this->wire($sent));
        $parts = $sent->getAttachments();
        self::assertCount(1, $parts);
        self::assertInstanceOf(DataPart::class, $parts[0]);
        self::assertSame($m[1], $parts[0]->getContentId(), 'the img src is the Content-ID of the inline part');
        self::assertStringContainsString('Content-ID: <' . $m[1] . '>', $sent->toString());
        self::assertStringContainsString('Content-Type: multipart/related', $sent->toString());

        self::assertStringContainsString('background-color:#f3f6f4;', $html);
        self::assertStringContainsString('<p>Hello Ana &lt;script&gt;</p>', $html);
        self::assertStringNotContainsString('<b>"Acme"', $html);
        self::assertSame(0, preg_match('/src="(?!cid:)/', $html), 'no remote image');
        self::assertStringNotContainsString('http', $html);

        self::assertStringStartsWith("<b>\"Acme\" & Co</b>\n\nHello Ana <script>", $text, 'the plain-text part is not HTML-escaped');
        self::assertStringNotContainsString('&lt;', $text);
        self::assertStringNotContainsString('cid:', $text);
    }

    public function testWithoutAProviderTheEmailIsByteIdenticalToBrandOff(): void
    {
        $none = $this->boot(null, null, ['brand' => ['name' => 'Fallback Co']]);
        $off  = $this->boot(new StaticBrandProvider(BrandFixtures::brand()), new MemoryBrandAssetReader(), ['brand' => ['mode' => 'off', 'name' => 'Fallback Co']]);

        foreach ([$none, $off] as $kernel) {
            $email = $this->email();
            self::assertSame($email, $this->helper($kernel)->apply($email));
            self::assertSame(['user_name' => 'Ana <script>'], $email->getContext());
            $this->send($kernel, $email);
        }

        $a = $this->sent($none);
        $b = $this->sent($off);
        self::assertSame($a->getHtmlBody(), $b->getHtmlBody());
        self::assertSame($a->getTextBody(), $b->getTextBody());
        self::assertSame([], $a->getAttachments());
        self::assertSame([], $b->getAttachments());
        self::assertStringContainsString('Fallback Co', (string) $a->getHtmlBody());
        self::assertStringContainsString('background-color:' . BrandedEmailHelper::DEFAULT_COLORS['paper'] . ';', (string) $a->getHtmlBody());

        // An e-mail that never used the helper renders the same body.
        $plain = $this->email();
        $this->send($none, $plain);
        self::assertSame($a->getHtmlBody(), $this->sent($none, 1)->getHtmlBody());
    }

    public function testAProviderFailureNeverFailsTheSend(): void
    {
        $kernel = $this->boot(new StaticBrandProvider(), new MemoryBrandAssetReader(), ['brand' => ['name' => 'Fallback Co']]);

        $this->send($kernel, $this->helper($kernel)->apply($this->email()));

        self::assertStringContainsString('Fallback Co', (string) $this->sent($kernel)->getHtmlBody());
    }

    public function testTheLayoutRendersWithoutAContextEntry(): void
    {
        $kernel = $this->boot(null, null);
        $twig   = $this->service($kernel, Environment::class);

        $html = $twig->render('email/welcome.html.twig', ['user_name' => 'Ana']);
        self::assertStringContainsString('<p>Hello Ana</p>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<span', $html, 'no name configured: no header');

        $hostile = $twig->render('email/welcome.html.twig', ['user_name' => 'Ana', 'nowo_mail_brand' => [
            'name'   => 'Acme',
            'colors' => ['paper' => 'red;}</style><script>alert(1)</script>'],
            'logo'   => ['src' => 'https://tracker.example/pixel.png', 'width' => 1, 'height' => 1],
            'mark'   => ['src' => 'cid:nowo-brand-mark.png', 'width' => 40, 'height' => 40],
        ]]);
        self::assertStringNotContainsString('tracker.example', $hostile);
        self::assertStringNotContainsString('<script', $hostile);
        self::assertStringContainsString('<img src="cid:nowo-brand-mark.png" width="40" height="40" alt=""', $hostile);
        self::assertStringContainsString('<span style="vertical-align:middle;">Acme</span>', $hostile);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(?StaticBrandProvider $provider, ?MemoryBrandAssetReader $reader, array $config = []): MailKitTestKernel
    {
        $kernel = $this->bootKernel($config, extra: static function (ContainerConfigurator $container) use ($provider, $reader): void {
            $container->extension('twig', ['paths' => ['%kernel.project_dir%/tests/Fixtures/templates']]);
            $services = $container->services();
            if ($provider instanceof StaticBrandProvider) {
                $services->set(BrandProviderInterface::class)->synthetic()->public();
            }
            if ($reader instanceof MemoryBrandAssetReader) {
                $services->set(BrandAssetReaderInterface::class)->synthetic()->public();
            }
        });
        if ($provider instanceof StaticBrandProvider) {
            $kernel->getContainer()->set(BrandProviderInterface::class, $provider);
        }
        if ($reader instanceof MemoryBrandAssetReader) {
            $kernel->getContainer()->set(BrandAssetReaderInterface::class, $reader);
        }

        return $kernel;
    }

    /**
     * Renders like the framework's mailer listener (the memory test transport has no event dispatcher), then sends.
     */
    private function send(MailKitTestKernel $kernel, TemplatedEmail $email): void
    {
        (new BodyRenderer($this->service($kernel, Environment::class)))->render($email);
        $this->mailer($kernel)->send($email);
    }

    private function helper(MailKitTestKernel $kernel): BrandedEmailHelper
    {
        return $this->service($kernel, BrandedEmailHelper::class);
    }

    private function email(): TemplatedEmail
    {
        return (new TemplatedEmail())
            ->from('app@example.com')
            ->to('a@example.com')
            ->subject('Welcome')
            ->htmlTemplate('email/welcome.html.twig')
            ->textTemplate('email/welcome.txt.twig')
            ->context(['user_name' => 'Ana <script>']);
    }

    private function sent(MailKitTestKernel $kernel, int $index = 0): Email
    {
        $sent = $this->store($kernel)->sentVia('fallback');
        self::assertArrayHasKey($index, $sent);
        $message = $sent[$index]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $message);

        return $message;
    }

    /**
     * The HTML as it travels: the body of the prepared text/html part (CID references rewritten to Content-IDs).
     */
    private function wire(Email $email): string
    {
        $parts = [$email->getBody()];
        while ($parts !== []) {
            $part = array_shift($parts);
            if ($part instanceof AbstractMultipartPart) {
                array_push($parts, ...$part->getParts());
            } elseif ($part instanceof TextPart && !$part instanceof DataPart && $part->getMediaSubtype() === 'html') {
                return $part->getBody();
            }
        }
        self::fail('No HTML part.');
    }
}
