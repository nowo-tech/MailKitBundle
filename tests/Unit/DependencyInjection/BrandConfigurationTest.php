<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\DependencyInjection;

use Nowo\Brand\Contract\BrandAssetReaderInterface;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\MailKitBundle\Brand\BrandedEmailHelper;
use Nowo\MailKitBundle\DependencyInjection\Configuration;
use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Nowo\MailKitBundle\Twig\MailKitBrandTwigExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Spec 220 S7: `nowo_mail_kit.brand`.
 */
final class BrandConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = $this->process([]);

        self::assertSame([
            'mode'            => 'auto',
            'name'            => null,
            'colors'          => BrandedEmailHelper::DEFAULT_COLORS,
            'max_image_bytes' => 524_288,
            'max_total_bytes' => 1_048_576,
            'max_image_side'  => 4096,
        ], $config['brand']);
    }

    public function testShorthandNameAndColours(): void
    {
        self::assertSame('off', $this->process(['brand' => 'off'])['brand']['mode']);

        $brand = $this->process(['brand' => ['name' => '  Acme  ', 'colors' => ['primary' => '#ABCDEF']]])['brand'];
        self::assertSame('Acme', $brand['name']);
        self::assertSame('#abcdef', $brand['colors']['primary']);
        self::assertSame(BrandedEmailHelper::DEFAULT_COLORS['text'], $brand['colors']['text']);
    }

    public function testInvalidValuesAreRefused(): void
    {
        foreach ([
            ['mode' => 'on'],
            ['name' => "Ac\u{202E}me"],
            ['name' => "Acme\nBcc: x@example.com"],
            ['name' => ''],
            ['name' => str_repeat('x', 81)],
            ['colors' => ['primary' => 'red']],
            ['colors' => ['primary' => '#fff']],
            ['colors' => ['paper' => '#ffffff;}']],
            ['colors' => ['unknown' => '#ffffff']],
            ['max_image_bytes' => 100],
            ['max_image_bytes' => 3_000_000],
            ['max_total_bytes' => 6_000_000],
            ['max_image_side' => 10_000],
            ['max_image_bytes' => 600_000, 'max_total_bytes' => 500_000],
        ] as $brand) {
            try {
                $this->process(['brand' => $brand]);
                self::fail('Refused: ' . json_encode($brand));
            } catch (InvalidConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testServicesWithTheBrandProvider(): void
    {
        $container = $this->load(['brand' => ['name' => 'Acme', 'max_image_side' => 2048]]);

        $helper = $container->getDefinition(BrandedEmailHelper::class);
        self::assertTrue($helper->isPublic());
        self::assertSame('Acme', $helper->getArgument('$defaultName'));
        self::assertSame(2048, $helper->getArgument('$maxImageSide'));
        $provider = $helper->getArgument('$provider');
        self::assertInstanceOf(Reference::class, $provider);
        self::assertSame(BrandProviderInterface::class, (string) $provider);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $provider->getInvalidBehavior());
        $reader = $helper->getArgument('$reader');
        self::assertInstanceOf(Reference::class, $reader);
        self::assertSame(BrandAssetReaderInterface::class, (string) $reader);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $reader->getInvalidBehavior());
        self::assertTrue($container->getDefinition(MailKitBrandTwigExtension::class)->hasTag('twig.extension'));
    }

    public function testOffIgnoresTheProvider(): void
    {
        $helper = $this->load(['brand' => 'off'])->getDefinition(BrandedEmailHelper::class);

        self::assertNull($helper->getArgument('$provider'));
        self::assertNull($helper->getArgument('$reader'));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['NowoInstanceSettingsBundle' => 'x', 'SecurityBundle' => 'y']);
        (new NowoMailKitExtension())->load([$config], $container);

        return $container;
    }
}
