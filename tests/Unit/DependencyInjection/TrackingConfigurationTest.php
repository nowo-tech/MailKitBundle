<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\MailKitBundle\DependencyInjection\Configuration;
use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Nowo\MailKitBundle\Integration\UserKit\EmailTrackingPersonalDataEraser;
use Nowo\MailKitBundle\Integration\UserKit\EmailTrackingPersonalDataExportProvider;
use Nowo\MailKitBundle\Mailer\RuntimeTransport;
use Nowo\MailKitBundle\Tracking\ConfiguredTrackableLinkFilter;
use Nowo\MailKitBundle\Tracking\DefaultTrackingRecipientResolver;
use Nowo\MailKitBundle\Tracking\EmailLinkRewriterInterface;
use Nowo\MailKitBundle\Tracking\EmailTracker;
use Nowo\MailKitBundle\Tracking\EmailTrackingPolicyInterface;
use Nowo\MailKitBundle\Tracking\Http\TrackingPixelController;
use Nowo\MailKitBundle\Tracking\Message\RecordMailOpenHandler;
use Nowo\MailKitBundle\Tracking\NullEmailLinkRewriter;
use Nowo\MailKitBundle\Tracking\TrackableLinkFilterInterface;
use Nowo\MailKitBundle\Tracking\TrackingRecipientResolverInterface;
use Nowo\MailKitBundle\Tracking\TrackingTokenSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

use function array_filter;
use function array_keys;
use function str_contains;
use function str_repeat;

final class TrackingConfigurationTest extends TestCase
{
    private const ENABLED = ['enabled' => true, 'policy' => 'app.policy', 'public_base_url' => 'https://app.example.com/'];

    /**
     * @param array<string, mixed> $tracking
     *
     * @return array<string, mixed>
     */
    private function process(array $tracking): array
    {
        /** @var array{tracking: array<string, mixed>} $config */
        $config = (new Processor())->processConfiguration(new Configuration(), [['tracking' => $tracking]]);

        return $config['tracking'];
    }

    public function testDefaults(): void
    {
        $tracking = $this->process([]);
        self::assertFalse($tracking['enabled']);
        self::assertNull($tracking['policy']);
        self::assertNull($tracking['public_base_url']);
        self::assertSame('/e/o', $tracking['path_prefix']);
        self::assertNull($tracking['secret']);
        self::assertSame('P90D', $tracking['retention']);
        self::assertNull($tracking['bus']);
        self::assertTrue($tracking['skip_redacted']);
        self::assertSame([], $tracking['extra_never_track_templates']);
        self::assertSame(1000, $tracking['max_opens_per_send']);
        self::assertSame([], $tracking['links']['allowed_hosts']);
        self::assertSame(Configuration::ESSENTIAL_LINK_PATTERNS, $tracking['links']['essential_patterns']);
        self::assertSame(Configuration::MACHINE_IP_RANGES, $tracking['machine_opens']['ip_ranges']);
        self::assertSame(90 * 86_400, Configuration::daysSeconds('P90D'));
        self::assertSame('https://app.example.com', $this->process(self::ENABLED)['public_base_url']);
        self::assertSame(['17.0.0.0/8', '2001:db8::/32'], $this->process(['machine_opens' => ['ip_ranges' => ['17.0.0.0/8', '2001:db8::/32']]])['machine_opens']['ip_ranges']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalid(): iterable
    {
        yield 'no policy' => [['enabled' => true, 'public_base_url' => 'https://app.example.com'], 'needs tracking.policy'];
        yield 'no base url' => [['enabled' => true, 'policy' => 'p'], 'needs tracking.public_base_url'];
        yield 'relative base url' => [['public_base_url' => '/app'], 'absolute http(s) URL'];
        yield 'base url with query' => [['public_base_url' => 'https://app.example.com/?x=1'], 'absolute http(s) URL'];
        yield 'base url with user info' => [['public_base_url' => 'https://u:p@app.example.com'], 'absolute http(s) URL'];
        yield 'prefix' => [['path_prefix' => 'e/o'], 'absolute path'];
        yield 'prefix with placeholder' => [['path_prefix' => '/e/{x}'], 'absolute path'];
        yield 'retention hours' => [['retention' => 'PT12H'], 'between P1D and P3650D'];
        yield 'retention zero' => [['retention' => 'P0D'], 'between P1D and P3650D'];
        yield 'retention too long' => [['retention' => 'P3651D'], 'between P1D and P3650D'];
        yield 'ip range' => [['machine_opens' => ['ip_ranges' => ['17.0.0.0/33']]], 'IP addresses or CIDR ranges'];
    }

    /**
     * @param array<string, mixed> $tracking
     */
    #[DataProvider('invalid')]
    public function testInvalid(array $tracking, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->process($tracking);
    }

    public function testDisabledRegistersNothing(): void
    {
        $container = $this->container(['doctrine']);
        (new NowoMailKitExtension())->load([[]], $container);
        $ids = array_filter([...array_keys($container->getDefinitions()), ...array_keys($container->getAliases())], static fn (string $id): bool => str_contains($id, '\\Tracking\\') || str_contains($id, 'EmailTracking'));
        self::assertSame([], $ids);
        self::assertNull($container->getDefinition(RuntimeTransport::class)->getArgument(9));
    }

    public function testEnabledDefaultsAndHostPorts(): void
    {
        $default = $this->container(['doctrine']);
        (new NowoMailKitExtension())->load([['tracking' => self::ENABLED]], $default);
        self::assertSame('app.policy', (string) $default->getAlias(EmailTrackingPolicyInterface::class));
        self::assertSame(NullEmailLinkRewriter::class, (string) $default->getAlias(EmailLinkRewriterInterface::class));
        self::assertSame(ConfiguredTrackableLinkFilter::class, (string) $default->getAlias(TrackableLinkFilterInterface::class));
        self::assertSame([TrackingTokenSigner::class, 'fromKernelSecret'], $default->getDefinition(TrackingTokenSigner::class)->getFactory());
        self::assertSame('%kernel.secret%', $default->getDefinition(TrackingTokenSigner::class)->getArgument(0));
        self::assertSame('https://app.example.com/e/o', $default->getDefinition(EmailTracker::class)->getArgument(7));
        $runtime = $default->getDefinition(RuntimeTransport::class)->getArgument(9);
        self::assertInstanceOf(Reference::class, $runtime);
        self::assertSame(EmailTracker::class, (string) $runtime);
        self::assertNull($default->getDefinition(TrackingPixelController::class)->getArgument(3));
        self::assertFalse($default->hasDefinition(RecordMailOpenHandler::class));
        self::assertFalse($default->hasDefinition(EmailTrackingPersonalDataEraser::class), 'personal_data is opt-in.');

        $custom = $this->container(['doctrine']);
        (new NowoMailKitExtension())->load([[
            'tracking'      => self::ENABLED + ['link_rewriter' => 'app.short_links', 'link_filter' => 'app.links', 'recipient_resolver' => 'app.recipients', 'secret' => '%env(MAIL_KIT_TRACKING_SECRET)%', 'bus' => 'messenger.default_bus'],
            'personal_data' => ['enabled' => true],
        ]], $custom);
        self::assertSame('app.short_links', (string) $custom->getAlias(EmailLinkRewriterInterface::class));
        self::assertSame('app.links', (string) $custom->getAlias(TrackableLinkFilterInterface::class));
        self::assertNull($custom->getDefinition(TrackingTokenSigner::class)->getFactory());
        self::assertSame('messenger.default_bus', (string) $custom->getDefinition(TrackingPixelController::class)->getArgument(3));
        self::assertTrue($custom->hasDefinition(RecordMailOpenHandler::class));
        self::assertSame('app.recipients', (string) $custom->getAlias(TrackingRecipientResolverInterface::class));
        self::assertSame([['priority' => EmailTrackingPersonalDataEraser::PRIORITY]], $custom->getDefinition(EmailTrackingPersonalDataEraser::class)->getTag('nowo_user_kit.personal_data.eraser'));
        self::assertSame([['priority' => EmailTrackingPersonalDataExportProvider::PRIORITY]], $custom->getDefinition(EmailTrackingPersonalDataExportProvider::class)->getTag('nowo_user_kit.personal_data.export_provider'));

        $resolver = $this->container(['doctrine']);
        (new NowoMailKitExtension())->load([['tracking' => self::ENABLED, 'personal_data' => ['enabled' => true]]], $resolver);
        self::assertSame(DefaultTrackingRecipientResolver::class, (string) $resolver->getAlias(TrackingRecipientResolverInterface::class));
    }

    public function testTrackingNeedsTheMailerDecorator(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('tracking.enabled needs nowo_mail_kit.mailer.decorate: true');
        (new Processor())->processConfiguration(new Configuration(), [['mailer' => ['decorate' => false], 'tracking' => self::ENABLED]]);
    }

    public function testLiteralSecretsAreCheckedAtCompileTime(): void
    {
        $kernelMode = $this->container(['doctrine']);
        (new NowoMailKitExtension())->load([['tracking' => self::ENABLED + ['previous_secrets' => ['old-app-secret']]]], $kernelMode);
        self::assertSame(['old-app-secret'], $kernelMode->getDefinition(TrackingTokenSigner::class)->getArgument(1), 'Previous kernel secrets: any length, derived.');

        $env = $this->container(['doctrine']);
        (new NowoMailKitExtension())->load([['tracking' => self::ENABLED + ['secret' => '%env(S)%', 'previous_secrets' => ['%env(OLD)%']]]], $env);
        self::assertNull($env->getDefinition(TrackingTokenSigner::class)->getFactory());

        foreach ([['secret' => 'short'], ['secret' => str_repeat('s', 32), 'previous_secrets' => ['short']]] as $secrets) {
            try {
                (new NowoMailKitExtension())->load([['tracking' => self::ENABLED + $secrets]], $this->container(['doctrine']));
                self::fail('Short literal secret accepted.');
            } catch (InvalidConfigurationException $exception) {
                self::assertStringContainsString('at least 32 bytes', $exception->getMessage());
            }
        }
    }

    public function testEnabledNeedsDoctrine(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('tracking requires doctrine/doctrine-bundle');
        (new NowoMailKitExtension())->load([['tracking' => self::ENABLED, 'log' => ['enabled' => false]]], $this->container([]));
    }

    /**
     * @param list<string> $extensions
     */
    private function container(array $extensions): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['NowoInstanceSettingsBundle' => 'x', 'SecurityBundle' => 'y']);
        foreach ($extensions as $alias) {
            $container->registerExtension(new class($alias) extends Extension {
                public function __construct(private readonly string $alias)
                {
                }

                public function load(array $configs, ContainerBuilder $container): void
                {
                }

                public function getAlias(): string
                {
                    return $this->alias;
                }
            });
        }

        return $container;
    }
}
