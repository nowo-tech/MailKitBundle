<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Brand;

use Nowo\Brand\Contract\BrandAssetOrigin;
use Nowo\Brand\Contract\BrandAssetSlot;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\MailKitBundle\Brand\BrandedEmailHelper;
use Nowo\MailKitBundle\Brand\BrandedEmailImage;
use Nowo\MailKitBundle\Brand\BrandedEmailPart;
use Nowo\MailKitBundle\Tests\Support\BrandFixtures;
use Nowo\MailKitBundle\Tests\Support\MemoryBrandAssetReader;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use Nowo\MailKitBundle\Tests\Support\StaticBrandProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

use function quoted_printable_decode;
use function serialize;
use function str_repeat;

final class BrandedEmailHelperTest extends TestCase
{
    public function testApplyAttachesTheLogoAsAnInlineCidPartAndSetsTheBrandView(): void
    {
        $reader = new MemoryBrandAssetReader([BrandAssetSlot::LOGO_LIGHT => BrandFixtures::png(200, 50)]);
        $helper = $this->helper(new StaticBrandProvider(BrandFixtures::brand([BrandAssetSlot::LOGO_LIGHT => BrandFixtures::asset(BrandAssetSlot::LOGO_LIGHT)])), $reader);

        $email = $helper->apply($this->email()->context(['user' => 'Ana']));

        $parts = $email->getAttachments();
        self::assertCount(1, $parts);
        self::assertInstanceOf(DataPart::class, $parts[0]);
        self::assertSame('nowo-brand-logo-light.png', $parts[0]->getFilename());
        self::assertSame('image/png', $parts[0]->getContentType());
        self::assertSame('inline', $parts[0]->getPreparedHeaders()->getHeaderBody('Content-Disposition'));
        self::assertSame(BrandFixtures::png(200, 50), $parts[0]->getBody());

        $context = $email->getContext();
        self::assertSame('Ana', $context['user']);
        $view = $context[BrandedEmailHelper::CONTEXT_KEY];
        self::assertSame('Acme', $view['name']);
        self::assertSame(['src' => 'cid:nowo-brand-logo-light.png', 'width' => 160, 'height' => 40], $view['logo']);
        self::assertNull($view['mark']);
        self::assertSame('#1f6f54', $view['colors']['primary']);
        self::assertSame('#ffffff', $view['colors']['primary_contrast']);
        self::assertSame('#0f1c18', $view['colors']['text']);
        self::assertSame('#f3f6f4', $view['colors']['paper']);
        self::assertSame('#ffffff', $view['colors']['surface']);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $view['colors']['muted']);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $view['colors']['border']);
        self::assertSame($view, $helper->view($view), 'the view survives its own re-check');

        self::assertSame([['slot' => BrandAssetSlot::LOGO_LIGHT, 'url' => '/_settings-file/brand/logo.png', 'variant' => null]], $reader->reads);
    }

    public function testWithoutALogoTheMarkIsUsed(): void
    {
        $reader = new MemoryBrandAssetReader([BrandAssetSlot::MARK => BrandFixtures::png(512, 512)]);
        $helper = $this->helper(new StaticBrandProvider(BrandFixtures::brand([BrandAssetSlot::MARK => BrandFixtures::asset(BrandAssetSlot::MARK, 512, 512)])), $reader);

        $view = $helper->apply($this->email())->getContext()[BrandedEmailHelper::CONTEXT_KEY];

        self::assertNull($view['logo']);
        self::assertSame(['src' => 'cid:nowo-brand-mark.png', 'width' => 40, 'height' => 40], $view['mark']);
    }

    public function testWithoutAProviderTheEmailIsByteIdentical(): void
    {
        foreach ([$this->helper(null, new MemoryBrandAssetReader([BrandAssetSlot::LOGO_LIGHT => BrandFixtures::png()])), new BrandedEmailHelper()] as $helper) {
            $email  = $this->email()->context(['user' => 'Ana']);
            $before = serialize($email);

            self::assertSame($email, $helper->apply($email));
            self::assertSame($before, serialize($email));
            self::assertNull($helper->embed($email, BrandAssetSlot::LOGO_LIGHT));
            self::assertSame($before, serialize($email));
            self::assertNull($helper->brand());
        }
    }

    public function testAFailingProviderLeavesTheEmailUntouched(): void
    {
        $logger = new RecordingLogger();
        $helper = $this->helper(new StaticBrandProvider(), new MemoryBrandAssetReader(), $logger);
        $email  = $this->email();
        $before = serialize($email);

        self::assertSame($before, serialize($helper->apply($email)));
        self::assertNull($helper->embed($email, BrandAssetSlot::MARK));
        self::assertTrue($logger->has('the brand provider failed'));
    }

    public function testANonRasterUploadIsRefused(): void
    {
        $logger = new RecordingLogger();
        // The upload claims to be a PNG, but the reader hands out SVG markup: never attached.
        $reader = new MemoryBrandAssetReader([BrandAssetSlot::LOGO_LIGHT => BrandFixtures::SVG, BrandAssetSlot::MARK => '<html><body>x</body></html>']);
        $helper = $this->helper(new StaticBrandProvider(BrandFixtures::brand([
            BrandAssetSlot::LOGO_LIGHT => BrandFixtures::asset(BrandAssetSlot::LOGO_LIGHT),
            BrandAssetSlot::MARK       => BrandFixtures::asset(BrandAssetSlot::MARK, 64, 64),
        ])), $reader, $logger);

        $email = $helper->apply($this->email());

        self::assertSame([], $email->getAttachments());
        $view = $email->getContext()[BrandedEmailHelper::CONTEXT_KEY];
        self::assertNull($view['logo']);
        self::assertNull($view['mark']);
        self::assertSame('Acme', $view['name'], 'name and colours still apply');
        self::assertTrue($logger->has('not PNG, JPEG or WebP'));
    }

    public function testAConfigDefaultSvgIsNotAttachedEither(): void
    {
        $svg    = BrandFixtures::asset(BrandAssetSlot::LOGO_LIGHT, 10, 10, 'image/svg+xml', BrandAssetOrigin::ConfigDefault, '/brand/logo.svg');
        $helper = $this->helper(new StaticBrandProvider(BrandFixtures::brand([BrandAssetSlot::LOGO_LIGHT => $svg])), new MemoryBrandAssetReader([BrandAssetSlot::LOGO_LIGHT => BrandFixtures::SVG]));

        $email = $this->email();
        self::assertNull($helper->embed($email, BrandAssetSlot::LOGO_LIGHT));
        self::assertSame([], $email->getAttachments());
    }

    public function testAConfigDefaultPngIsAttached(): void
    {
        $png    = BrandFixtures::asset(BrandAssetSlot::LOGO_LIGHT, 200, 50, 'image/png', BrandAssetOrigin::ConfigDefault, '/brand/logo.png');
        $helper = $this->helper(new StaticBrandProvider(BrandFixtures::brand([BrandAssetSlot::LOGO_LIGHT => $png])), new MemoryBrandAssetReader([BrandAssetSlot::LOGO_LIGHT => BrandFixtures::png()]));

        self::assertSame('cid:nowo-brand-logo-light.png', $helper->embed($this->email(), BrandAssetSlot::LOGO_LIGHT));
    }

    public function testAHeaderThatDoesNotMatchItsMagicIsRefused(): void
    {
        $logger = new RecordingLogger();
        $helper = $this->helper(
            new StaticBrandProvider(BrandFixtures::brand([BrandAssetSlot::LOGO_LIGHT => BrandFixtures::asset(BrandAssetSlot::LOGO_LIGHT)])),
            new MemoryBrandAssetReader([BrandAssetSlot::LOGO_LIGHT => "\x89PNG\r\n\x1a\n<script>alert(1)</script>"]),
            $logger,
        );

        self::assertNull($helper->embed($this->email(), BrandAssetSlot::LOGO_LIGHT));
        self::assertTrue($logger->has('header does not match'));
    }

    public function testAReaderFailureMeansNoLogo(): void
    {
        $logger = new RecordingLogger();
        $helper = $this->helper(new StaticBrandProvider(BrandFixtures::brand([
            BrandAssetSlot::LOGO_LIGHT => BrandFixtures::asset(BrandAssetSlot::LOGO_LIGHT),
            BrandAssetSlot::MARK       => BrandFixtures::asset(BrandAssetSlot::MARK, 64, 64),
        ])), new MemoryBrandAssetReader(), $logger);

        $email = $helper->apply($this->email());

        self::assertSame([], $email->getAttachments());
        self::assertNull($email->getContext()[BrandedEmailHelper::CONTEXT_KEY]['logo']);
        self::assertTrue($logger->has('could not be read'));
        foreach ($logger->records as $record) {
            self::assertArrayNotHasKey('url', $record['context']);
        }
    }

    public function testWithoutAReaderNoImageIsAttachedAndNothingIsFetched(): void
    {
        $remote = BrandFixtures::asset(BrandAssetSlot::LOGO_LIGHT, url: 'https://cdn.example.com/logo.png');
        $helper = $this->helper(new StaticBrandProvider(BrandFixtures::brand([BrandAssetSlot::LOGO_LIGHT => $remote])), null);

        $email = $helper->apply($this->email());

        self::assertSame([], $email->getAttachments());
        self::assertNull($email->getContext()[BrandedEmailHelper::CONTEXT_KEY]['logo']);
    }

    public function testSizeLimits(): void
    {
        $logger = new RecordingLogger();
        $brand  = BrandFixtures::brand([
            BrandAssetSlot::LOGO_LIGHT               => BrandFixtures::asset(BrandAssetSlot::LOGO_LIGHT),
            BrandAssetSlot::MARK                     => BrandFixtures::asset(BrandAssetSlot::MARK, 64, 64),
            BrandAssetSlot::extra('email_mascot')    => BrandFixtures::asset(BrandAssetSlot::extra('email_mascot'), 300, 300),
            BrandAssetSlot::extra('email_signature') => BrandFixtures::asset(BrandAssetSlot::extra('email_signature'), 100, 40),
        ]);
        $reader = new MemoryBrandAssetReader([
            BrandAssetSlot::LOGO_LIGHT               => BrandFixtures::png(200, 50, 3000),
            BrandAssetSlot::MARK                     => BrandFixtures::png(64, 64, 1500),
            BrandAssetSlot::extra('email_mascot')    => BrandFixtures::png(300, 300, 1000),
            BrandAssetSlot::extra('email_signature') => '',
        ]);
        $helper = new BrandedEmailHelper(provider: new StaticBrandProvider($brand), reader: $reader, maxImageBytes: 2048, maxTotalBytes: 2500, logger: $logger);
        $email  = $this->email();

        self::assertNull($helper->embed($email, BrandAssetSlot::LOGO_LIGHT), 'larger than max_image_bytes');
        self::assertSame('cid:nowo-brand-mark.png', $helper->embed($email, BrandAssetSlot::MARK));
        self::assertNull($helper->embed($email, BrandAssetSlot::extra('email_mascot')), 'the total would exceed max_total_bytes');
        self::assertNull($helper->embed($email, BrandAssetSlot::extra('email_signature')), 'empty bytes');
        self::assertCount(1, $email->getAttachments());
        self::assertTrue($logger->has('max_image_bytes'));
        self::assertTrue($logger->has('max_total_bytes'));
    }

    public function testDimensionsAboveTheLimitAreRefused(): void
    {
        $helper = new BrandedEmailHelper(
            provider: new StaticBrandProvider(BrandFixtures::brand([BrandAssetSlot::LOGO_LIGHT => BrandFixtures::asset(BrandAssetSlot::LOGO_LIGHT, 5000, 100)])),
            reader: new MemoryBrandAssetReader([BrandAssetSlot::LOGO_LIGHT => BrandFixtures::png(5000, 100)]),
        );

        self::assertNull($helper->embed($this->email(), BrandAssetSlot::LOGO_LIGHT));
    }

    public function testOnlyEmailSlotsMayBeEmbeddedAndTheSamePartIsReused(): void
    {
        $logger = new RecordingLogger();
        $brand  = BrandFixtures::brand([
            BrandAssetSlot::FAVICON               => BrandFixtures::asset(BrandAssetSlot::FAVICON, 32, 32),
            BrandAssetSlot::LOGO_DARK             => BrandFixtures::asset(BrandAssetSlot::LOGO_DARK),
            BrandAssetSlot::extra('mascot')       => BrandFixtures::asset(BrandAssetSlot::extra('mascot'), 64, 64),
            BrandAssetSlot::extra('email_mascot') => BrandFixtures::asset(BrandAssetSlot::extra('email_mascot'), 1200, 300),
        ]);
        $reader = new MemoryBrandAssetReader([
            BrandAssetSlot::FAVICON               => BrandFixtures::png(32, 32),
            BrandAssetSlot::LOGO_DARK             => BrandFixtures::png(),
            BrandAssetSlot::extra('mascot')       => BrandFixtures::png(64, 64),
            BrandAssetSlot::extra('email_mascot') => BrandFixtures::png(1200, 300),
        ]);
        $helper = $this->helper(new StaticBrandProvider($brand), $reader, $logger);
        $email  = $this->email();

        foreach ([BrandAssetSlot::FAVICON, BrandAssetSlot::LOGO_DARK, BrandAssetSlot::APP_ICON, 'extra.mascot', 'extra.email_', 'extra.email_X', '../etc', ''] as $slot) {
            self::assertNull($helper->embed($email, $slot), $slot);
        }
        self::assertTrue($logger->has('not allowed'));
        self::assertNull($helper->embed($email, BrandAssetSlot::MARK), 'allowed slot without an asset');

        self::assertSame('cid:nowo-brand-extra-email-mascot.png', $helper->embed($email, 'extra.email_mascot'));
        self::assertSame('cid:nowo-brand-extra-email-mascot.png', $helper->embed($email, 'extra.email_mascot'));
        self::assertCount(1, $email->getAttachments());
        self::assertCount(1, $reader->reads, 'the second embed reuses the attached part');
    }

    public function testAnApplicationAttachmentWithABrandNameIsNeverUsedAsTheLogo(): void
    {
        $reader = new MemoryBrandAssetReader([BrandAssetSlot::LOGO_LIGHT => BrandFixtures::png(200, 50)]);
        $helper = $this->helper(new StaticBrandProvider(BrandFixtures::brand([BrandAssetSlot::LOGO_LIGHT => BrandFixtures::asset(BrandAssetSlot::LOGO_LIGHT)])), $reader);
        $user   = BrandFixtures::png(300, 300, 900);
        $email  = $this->email()->from('app@example.com')->attach($user, 'nowo-brand-logo-light.png', 'image/png');
        $email->addPart((new DataPart(BrandFixtures::png(64, 64), 'nowo-brand-mark.png', 'image/png'))->asInline());

        $view = $helper->apply($email)->getContext()[BrandedEmailHelper::CONTEXT_KEY];
        $logo = $view['logo'];
        self::assertIsArray($logo);

        self::assertCount(1, $reader->reads, 'the brand logo is read, not taken from the application attachment');
        self::assertMatchesRegularExpression('/^cid:nowo-brand-logo-light-[0-9a-f]{12}\.png$/', $logo['src']);
        self::assertSame(160, $logo['width']);
        self::assertSame($logo, $helper->view($view)['logo']);

        $parts = $email->getAttachments();
        self::assertCount(3, $parts);
        $ours = $parts[2];
        self::assertInstanceOf(BrandedEmailPart::class, $ours);
        self::assertSame(BrandFixtures::png(200, 50), $ours->getBody());
        self::assertSame($user, $parts[0]->getBody(), 'the application attachment is kept as is');

        // The cid: reference resolves to the brand part, never to the application attachment.
        $email->html('<img src="' . $logo['src'] . '" alt="">')->text('x');
        $raw = quoted_printable_decode($email->toString());
        self::assertStringContainsString('src="cid:' . $ours->getContentId() . '"', $raw);
        self::assertInstanceOf(DataPart::class, $parts[0]);
        self::assertStringNotContainsString('src="cid:' . $parts[0]->getContentId() . '"', $raw);

        self::assertSame($logo['src'], $helper->embed($email, BrandAssetSlot::LOGO_LIGHT), 'the helper reuses its own part');
        self::assertCount(3, $email->getAttachments());
        self::assertCount(1, $reader->reads);
    }

    public function testApplicationAttachmentsDoNotCountTowardsTheBrandTotal(): void
    {
        $helper = new BrandedEmailHelper(
            provider: new StaticBrandProvider(BrandFixtures::brand([BrandAssetSlot::MARK => BrandFixtures::asset(BrandAssetSlot::MARK, 64, 64)])),
            reader: new MemoryBrandAssetReader([BrandAssetSlot::MARK => BrandFixtures::png(64, 64)]),
            maxImageBytes: 2048,
            maxTotalBytes: 2048,
        );
        $email = $this->email()->attach(str_repeat('x', 4096), 'nowo-brand-big.png', 'image/png');

        self::assertSame('cid:nowo-brand-mark.png', $helper->embed($email, BrandAssetSlot::MARK));
    }

    public function testApplyAfterEmbedReusesTheLogoPart(): void
    {
        $reader = new MemoryBrandAssetReader([BrandAssetSlot::LOGO_LIGHT => BrandFixtures::png(480, 60)]);
        $helper = $this->helper(new StaticBrandProvider(BrandFixtures::brand([BrandAssetSlot::LOGO_LIGHT => BrandFixtures::asset(BrandAssetSlot::LOGO_LIGHT, 480, 60)])), $reader);
        $email  = $this->email();

        self::assertSame('cid:nowo-brand-logo-light.png', $helper->embed($email, BrandAssetSlot::LOGO_LIGHT));
        $view = $helper->apply($email)->getContext()[BrandedEmailHelper::CONTEXT_KEY];

        self::assertCount(1, $email->getAttachments());
        self::assertSame(['src' => 'cid:nowo-brand-logo-light.png', 'width' => 240, 'height' => 30], $view['logo']);
    }

    public function testDisplaySizes(): void
    {
        $brand = BrandFixtures::brand([
            BrandAssetSlot::LOGO_LIGHT          => BrandFixtures::asset(BrandAssetSlot::LOGO_LIGHT, 20, 10),
            BrandAssetSlot::extra('email_wide') => BrandFixtures::asset(BrandAssetSlot::extra('email_wide'), 1200, 300),
            BrandAssetSlot::extra('email_tall') => BrandFixtures::asset(BrandAssetSlot::extra('email_tall'), 300, 1200),
            BrandAssetSlot::extra('email_tiny') => BrandFixtures::asset(BrandAssetSlot::extra('email_tiny'), 100, 50),
        ]);
        $reader = new MemoryBrandAssetReader([
            BrandAssetSlot::LOGO_LIGHT          => BrandFixtures::png(20, 10),
            BrandAssetSlot::extra('email_wide') => BrandFixtures::png(1200, 300),
            BrandAssetSlot::extra('email_tall') => BrandFixtures::png(300, 1200),
            BrandAssetSlot::extra('email_tiny') => BrandFixtures::png(100, 50),
        ]);
        $helper = $this->helper(new StaticBrandProvider($brand), $reader);

        $view = $helper->apply($this->email())->getContext()[BrandedEmailHelper::CONTEXT_KEY];
        self::assertSame(['src' => 'cid:nowo-brand-logo-light.png', 'width' => 80, 'height' => 40], $view['logo'], 'a small logo is scaled to the header height');

        $image = (new ReflectionClass($helper))->getMethod('image');
        foreach (['email_wide' => [600, 150], 'email_tall' => [150, 600], 'email_tiny' => [100, 50]] as $id => [$width, $height]) {
            $result = $image->invoke($helper, $this->email(), $brand, BrandAssetSlot::extra($id));
            self::assertInstanceOf(BrandedEmailImage::class, $result);
            self::assertSame([$width, $height], [$result->width, $result->height], $id);
        }
    }

    public function testViewChecksEveryValueAgain(): void
    {
        $helper = new BrandedEmailHelper('Fallback Co', ['primary' => '#ABCDEF', 'text' => 'red;}</style>']);

        $fallback = $helper->view();
        self::assertSame('Fallback Co', $fallback['name']);
        self::assertSame('#abcdef', $fallback['colors']['primary'], 'configured colours are lower-cased');
        self::assertSame(BrandedEmailHelper::DEFAULT_COLORS['text'], $fallback['colors']['text'], 'an invalid configured colour falls back');
        self::assertSame($fallback, $helper->view('not an array'));

        $view = $helper->view([
            'name'   => "Evil\u{202E}Co",
            'colors' => ['primary' => 'red;}</style><script>', 'paper' => '#FFFFFF', 'surface' => 12, 'border' => '#0a0b0c'],
            'logo'   => ['src' => 'https://evil.example/x.png', 'width' => 100, 'height' => 40],
            'mark'   => ['src' => 'cid:nowo-brand-mark.png', 'width' => '40', 'height' => 40],
        ]);
        self::assertSame('Fallback Co', $view['name']);
        self::assertSame('#abcdef', $view['colors']['primary']);
        self::assertSame(BrandedEmailHelper::DEFAULT_COLORS['paper'], $view['colors']['paper'], 'upper-case hex is not what the helper produces');
        self::assertSame(BrandedEmailHelper::DEFAULT_COLORS['surface'], $view['colors']['surface']);
        self::assertSame('#0a0b0c', $view['colors']['border']);
        self::assertNull($view['logo']);
        self::assertNull($view['mark']);

        foreach ([
            ['src' => 'cid:nowo-brand-logo-light.png"onerror="x', 'width' => 100, 'height' => 40],
            ['src' => 'cid:other.png', 'width' => 100, 'height' => 40],
            ['src' => 'cid:nowo-brand-logo-light.svg', 'width' => 100, 'height' => 40],
            ['src' => 'cid:nowo-brand-logo-light.png', 'width' => 241, 'height' => 40],
            ['src' => 'cid:nowo-brand-logo-light.png', 'width' => 100, 'height' => 41],
            ['src' => 'cid:nowo-brand-logo-light.png', 'width' => 0, 'height' => 40],
            ['src' => 'cid:nowo-brand-logo-light.png', 'width' => 100, 'height' => 0],
            ['src' => 'cid:nowo-brand-logo-light.png', 'width' => 100],
            'cid:nowo-brand-logo-light.png',
        ] as $logo) {
            self::assertNull($helper->view(['logo' => $logo])['logo']);
        }
        self::assertSame(['src' => 'cid:nowo-brand-logo-light.png', 'width' => 100, 'height' => 40], $helper->view(['logo' => ['src' => 'cid:nowo-brand-logo-light.png', 'width' => 100, 'height' => 40]])['logo']);
        self::assertSame('Plain', $helper->view(['name' => '  Plain '])['name']);

        self::assertNull((new BrandedEmailHelper())->view()['name']);
        self::assertNull((new BrandedEmailHelper(str_repeat('x', 81)))->view()['name']);
    }

    public function testEachSendAsksTheProviderAgain(): void
    {
        $provider = new StaticBrandProvider(BrandFixtures::brand(name: 'First'));
        $helper   = $this->helper($provider, new MemoryBrandAssetReader());

        self::assertSame('First', $helper->apply($this->email())->getContext()[BrandedEmailHelper::CONTEXT_KEY]['name']);
        $provider->brand = BrandFixtures::brand(name: 'Second');
        self::assertSame('Second', $helper->apply($this->email())->getContext()[BrandedEmailHelper::CONTEXT_KEY]['name']);
        self::assertSame(2, $provider->calls);
        self::assertTrue((new ReflectionClass(BrandedEmailHelper::class))->isReadOnly(), 'no mutable state between messages');
    }

    public function testAnUnexpectedErrorWhileApplyingKeepsTheEmail(): void
    {
        $logger   = new RecordingLogger();
        $provider = new StaticBrandProvider(BrandFixtures::brand([BrandAssetSlot::LOGO_LIGHT => BrandFixtures::asset(BrandAssetSlot::LOGO_LIGHT)]));
        $email    = new class extends TemplatedEmail {
            public function getAttachments(): array
            {
                throw new RuntimeException('broken message (test)');
            }
        };
        $email->to('a@example.com')->subject('Hi');
        $helper = $this->helper($provider, new MemoryBrandAssetReader([BrandAssetSlot::LOGO_LIGHT => BrandFixtures::png()]), $logger);

        self::assertSame([], $helper->apply($email)->getContext());
        self::assertNull($helper->embed($email, BrandAssetSlot::LOGO_LIGHT));
        self::assertTrue($logger->has('could not be applied'));
        self::assertTrue($logger->has('could not be embedded'));
    }

    public function testImageValueObject(): void
    {
        $image = new BrandedEmailImage('nowo-brand-mark.png', 'image/png', 'x', 40, 40);

        self::assertSame('cid:nowo-brand-mark.png', $image->src());
        self::assertSame(['src' => 'cid:nowo-brand-mark.png', 'width' => 40, 'height' => 40], $image->toView());
    }

    public function testEmbedOnAPlainEmail(): void
    {
        $helper = $this->helper(new StaticBrandProvider(BrandFixtures::brand([BrandAssetSlot::MARK => BrandFixtures::asset(BrandAssetSlot::MARK, 64, 64)])), new MemoryBrandAssetReader([BrandAssetSlot::MARK => BrandFixtures::png(64, 64)]));
        $email  = (new Email())->from('app@example.com')->to('a@example.com')->subject('Hi');

        $cid = $helper->embed($email, BrandAssetSlot::MARK);
        self::assertSame('cid:nowo-brand-mark.png', $cid);

        $email->html('<p><img src="' . $cid . '" alt=""></p>')->text('Hi');
        $body = $email->toString();
        self::assertMatchesRegularExpression('/Content-ID: <[^>]+>/', $body);
        self::assertStringContainsString('Content-Disposition: inline', $body);
        self::assertStringNotContainsString('src="cid:nowo-brand-mark.png"', $body, 'Symfony rewrites the reference to the Content-ID');
    }

    private function helper(?BrandProviderInterface $provider, ?MemoryBrandAssetReader $reader, ?RecordingLogger $logger = null): BrandedEmailHelper
    {
        return new BrandedEmailHelper('Fallback Co', BrandedEmailHelper::DEFAULT_COLORS, $provider, $reader, logger: $logger);
    }

    private function email(): TemplatedEmail
    {
        return (new TemplatedEmail())->to('a@example.com')->subject('Welcome')->htmlTemplate('email/welcome.html.twig');
    }
}
