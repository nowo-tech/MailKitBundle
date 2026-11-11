<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\MailKitBundle\DependencyInjection\Compiler\MailerIntegrationPass;
use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Nowo\MailKitBundle\Dsn\MailerDsnProviderInterface;
use Nowo\MailKitBundle\Integration\UserKit\EmailsReceivedPersonalDataEraser;
use Nowo\MailKitBundle\Integration\UserKit\EmailsReceivedPersonalDataExportProvider;
use Nowo\MailKitBundle\Log\DefaultSentMailPersonalDataSubjectResolver;
use Nowo\MailKitBundle\Log\NullSentMailErasure;
use Nowo\MailKitBundle\Log\NullSentMailPersonalDataExporter;
use Nowo\MailKitBundle\Log\SentMailPersonalDataEraserInterface;
use Nowo\MailKitBundle\Log\SentMailPersonalDataExporterInterface;
use Nowo\MailKitBundle\Log\SentMailPersonalDataSubjectResolverInterface;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Mailer\RuntimeTransport;
use Nowo\MailKitBundle\NowoMailKitBundle;
use Nowo\MailKitBundle\Resend\ResendApiChecker;
use Nowo\MailKitBundle\Security\MailKitAccessCheckerInterface;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Nowo\MailKitBundle\Settings\LegacyResendSettings;
use Nowo\MailKitBundle\Settings\MailerSettingsSection;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Validator\ResendApiKeyAcceptedValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Transports;

final class NowoMailKitExtensionTest extends TestCase
{
    public function testPrependsTheProfilesAndTheAuthKitChecker(): void
    {
        $container = $this->container(['nowo_form_kit', 'nowo_auth_kit', 'doctrine']);
        (new NowoMailKitExtension())->prepend($container);

        self::assertSame(NowoMailKitExtension::formKitProfile(), $container->getExtensionConfig('nowo_form_kit')[0]['profiles'][NowoMailKitBundle::FORM_KIT_PROFILE]);
        self::assertSame(['outbound_mail_ready_checker' => NowoMailKitExtension::AUTH_KIT_CHECKER_ID], $container->getExtensionConfig('nowo_auth_kit')[0]);
        self::assertArrayHasKey('NowoMailKitBundle', $container->getExtensionConfig('doctrine')[0]['orm']['mappings']);

        $own = $this->container(['nowo_form_kit']);
        $own->prependExtensionConfig('nowo_form_kit', ['profiles' => [NowoMailKitBundle::FORM_KIT_PROFILE => ['translation_domain' => 'app']]]);
        (new NowoMailKitExtension())->prepend($own);
        self::assertCount(1, $own->getExtensionConfig('nowo_form_kit'));

        $none = $this->container([]);
        (new NowoMailKitExtension())->prepend($none);
        self::assertSame([], $none->getExtensionConfig('nowo_form_kit'));
    }

    public function testALegacyResendAreaIsRefusedWithTheResendProvider(): void
    {
        $refused = $this->container(['nowo_instance_settings']);
        $refused->prependExtensionConfig('nowo_mail_kit', ['resend' => ['enabled' => true]]);
        $refused->prependExtensionConfig('nowo_instance_settings', ['security' => ['area_roles' => ['mailer' => ['ROLE_ADMIN'], 'resend' => ['ROLE_SUPER_ADMIN']]]]);
        try {
            (new NowoMailKitExtension())->prepend($refused);
            self::fail('area_roles.resend must be refused.');
        } catch (LogicException $e) {
            self::assertStringContainsString('area_roles.resend is no longer used', $e->getMessage());
            self::assertStringContainsString('area_roles.mailer', $e->getMessage());
        }

        $off = $this->container(['nowo_instance_settings']);
        $off->prependExtensionConfig('nowo_mail_kit', ['resend' => ['enabled' => false]]);
        $off->prependExtensionConfig('nowo_instance_settings', ['security' => ['area_roles' => ['resend' => ['ROLE_SUPER_ADMIN']]]]);
        (new NowoMailKitExtension())->prepend($off);

        $merged = $this->container(['nowo_instance_settings']);
        $merged->prependExtensionConfig('nowo_mail_kit', ['resend' => ['enabled' => true]]);
        $merged->prependExtensionConfig('nowo_mail_kit', ['log' => ['enabled' => true]]);
        $merged->prependExtensionConfig('nowo_instance_settings', ['security' => ['area_roles' => ['mailer' => ['ROLE_SUPER_ADMIN']]]]);
        $merged->prependExtensionConfig('nowo_instance_settings', ['web_ui' => ['path_prefix' => '/settings']]);
        (new NowoMailKitExtension())->prepend($merged);
        $this->addToAssertionCount(1);
    }

    public function testCustomServicesAndSwitches(): void
    {
        $container = $this->container([]);
        (new NowoMailKitExtension())->load([[
            'mailer'   => ['dsn_provider' => 'app.dsn_provider', 'sender_provider' => 'app.sender_provider', 'decorate' => false],
            'security' => ['access_checker' => 'app.mail_checker'],
            'resend'   => ['enabled' => true],
            'web_ui'   => ['enabled' => true],
        ]], $container);

        self::assertSame('app.dsn_provider', (string) $container->getAlias(MailerDsnProviderInterface::class));
        self::assertSame('app.sender_provider', (string) $container->getAlias(SenderProviderInterface::class));
        self::assertSame(SenderProviderInterface::class, (string) $container->getDefinition(SenderPolicy::class)->getArgument(0));
        self::assertSame(['event_dispatcher', 'clock'], [(string) $container->getDefinition(RuntimeTransport::class)->getArgument(7), (string) $container->getDefinition(RuntimeTransport::class)->getArgument(8)]);

        $default = $this->container([]);
        (new NowoMailKitExtension())->load([[]], $default);
        self::assertSame(MailSettings::class, (string) $default->getAlias(SenderProviderInterface::class));
        self::assertSame('app.mail_checker', (string) $container->getAlias(MailKitAccessCheckerInterface::class));
        self::assertFalse($container->hasDefinition(MailerIntegrationPass::MAILER_ID));
        self::assertTrue($container->hasDefinition(ResendApiChecker::class));
        self::assertTrue($container->getDefinition(ResendApiKeyAcceptedValidator::class)->hasTag('validator.constraint_validator'));
        self::assertTrue($container->getDefinition(LegacyResendSettings::class)->hasTag('kernel.reset'));
        self::assertTrue($container->getDefinition(MailerSettingsSection::class)->getArgument(6));
        self::assertSame('nowo_mail_kit', (new NowoMailKitExtension())->getAlias());
    }

    public function testThePassDetectsTheMessageBus(): void
    {
        foreach ([[new Reference('messenger.default_bus'), true, true], [new Reference('messenger.default_bus'), false, false], [null, false, false]] as [$bus, $registered, $expected]) {
            $container = $this->container([]);
            $container->setDefinition(InstanceSettingsInterface::class, new Definition('stdClass'));
            $container->setDefinition('mailer.transports', new Definition(Transports::class));
            $container->setDefinition('mailer.transport_factory', new Definition(Transport::class));
            $container->setDefinition('mailer.mailer', new Definition(Mailer::class, [new Reference('mailer.transports'), $bus]));
            if ($registered) {
                $container->setDefinition('messenger.default_bus', new Definition('stdClass'));
            }
            $container->setDefinition(MailerIntegrationPass::MAILER_ID, new Definition('stdClass', ['$queues' => null]));
            (new MailerIntegrationPass())->process($container);
            self::assertSame($expected, $container->getDefinition(MailerIntegrationPass::MAILER_ID)->getArgument('$queues'));
        }

        $bare = $this->container([]);
        $bare->setDefinition(InstanceSettingsInterface::class, new Definition('stdClass'));
        $bare->setDefinition('mailer.transports', new Definition(Transports::class));
        $bare->setDefinition('mailer.transport_factory', new Definition(Transport::class));
        (new MailerIntegrationPass())->process($bare);
        $this->addToAssertionCount(1);
    }

    public function testThePassNeedsInstanceSettingsAndTheMailer(): void
    {
        $fromSettings = new ContainerBuilder();
        $fromSettings->setDefinition(MailSettings::class, new Definition(MailSettings::class));
        try {
            (new MailerIntegrationPass())->process($fromSettings);
            self::fail('Instance Settings must be required when the configuration is read from it.');
        } catch (LogicException $e) {
            self::assertStringContainsString('instance-settings-bundle', $e->getMessage());
        }
        $log = new ContainerBuilder();
        $log->setDefinition(SentMailRecorder::class, new Definition(SentMailRecorder::class));
        try {
            (new MailerIntegrationPass())->process($log);
            self::fail('Doctrine must be required by the log.');
        } catch (LogicException $e) {
            self::assertStringContainsString('requires doctrine/doctrine-bundle', $e->getMessage());
        }
        // 1.6: host providers only, no settings section: only the framework mailer is required.
        try {
            (new MailerIntegrationPass())->process(new ContainerBuilder());
            self::fail('The framework mailer must be required.');
        } catch (LogicException $e) {
            self::assertStringContainsString('requires the framework mailer', $e->getMessage());
        }
        $container = new ContainerBuilder();
        $container->setDefinition(InstanceSettingsInterface::class, new Definition('stdClass'));
        $this->expectExceptionMessage('requires the framework mailer');
        (new MailerIntegrationPass())->process($container);
    }

    /**
     * 1.7: the UserKit eraser / export provider are registered only on opt-in (personal_data.enabled: true, LY-L1)
     * with UserKit installed (dev dependency here) and the log entities mapped; the ports and the resolver SPI always.
     */
    public function testUserKitPersonalDataBridgeRegistration(): void
    {
        $byDefault = $this->container(['doctrine']);
        (new NowoMailKitExtension())->load([[]], $byDefault);
        self::assertFalse($byDefault->hasDefinition(EmailsReceivedPersonalDataEraser::class));
        self::assertFalse($byDefault->hasDefinition(EmailsReceivedPersonalDataExportProvider::class));
        self::assertSame(SentMailRecorder::class, (string) $byDefault->getAlias(SentMailPersonalDataEraserInterface::class));

        $default = $this->container(['doctrine']);
        (new NowoMailKitExtension())->load([['personal_data' => ['enabled' => true]]], $default);
        self::assertSame(SentMailRecorder::class, (string) $default->getAlias(SentMailPersonalDataExporterInterface::class));
        self::assertSame(SentMailRecorder::class, (string) $default->getAlias(SentMailPersonalDataEraserInterface::class));
        self::assertSame(DefaultSentMailPersonalDataSubjectResolver::class, (string) $default->getAlias(SentMailPersonalDataSubjectResolverInterface::class));
        self::assertSame('event_dispatcher', (string) $default->getDefinition(SentMailRecorder::class)->getArgument(7));
        self::assertSame([['priority' => EmailsReceivedPersonalDataEraser::PRIORITY]], $default->getDefinition(EmailsReceivedPersonalDataEraser::class)->getTag('nowo_user_kit.personal_data.eraser'));
        self::assertGreaterThanOrEqual(100, EmailsReceivedPersonalDataEraser::PRIORITY);
        self::assertSame([['priority' => EmailsReceivedPersonalDataExportProvider::PRIORITY]], $default->getDefinition(EmailsReceivedPersonalDataExportProvider::class)->getTag('nowo_user_kit.personal_data.export_provider'));
        self::assertSame([SentMailPersonalDataEraserInterface::class, SentMailPersonalDataSubjectResolverInterface::class], array_map('strval', $default->getDefinition(EmailsReceivedPersonalDataEraser::class)->getArguments()));
        self::assertSame([SentMailPersonalDataExporterInterface::class, SentMailPersonalDataSubjectResolverInterface::class], array_map('strval', $default->getDefinition(EmailsReceivedPersonalDataExportProvider::class)->getArguments()));

        $custom = $this->container(['doctrine']);
        (new NowoMailKitExtension())->load([['personal_data' => ['enabled' => true, 'subject_resolver' => 'app.mail_subjects']]], $custom);
        self::assertSame('app.mail_subjects', (string) $custom->getAlias(SentMailPersonalDataSubjectResolverInterface::class));

        $off = $this->container(['doctrine']);
        (new NowoMailKitExtension())->load([['personal_data' => ['enabled' => false]]], $off);
        self::assertFalse($off->hasDefinition(EmailsReceivedPersonalDataEraser::class));
        self::assertFalse($off->hasDefinition(EmailsReceivedPersonalDataExportProvider::class));
        self::assertTrue($off->hasAlias(SentMailPersonalDataExporterInterface::class));

        $unmapped = $this->container(['doctrine']);
        (new NowoMailKitExtension())->load([['log' => ['enabled' => false, 'map_entities' => false], 'personal_data' => ['enabled' => true]]], $unmapped);
        self::assertFalse($unmapped->hasDefinition(EmailsReceivedPersonalDataEraser::class));

        $noDoctrine = $this->container([]);
        (new NowoMailKitExtension())->load([['log' => ['enabled' => false], 'personal_data' => ['enabled' => true]]], $noDoctrine);
        self::assertSame(NullSentMailPersonalDataExporter::class, (string) $noDoctrine->getAlias(SentMailPersonalDataExporterInterface::class));
        self::assertSame(NullSentMailErasure::class, (string) $noDoctrine->getAlias(SentMailPersonalDataEraserInterface::class));
        self::assertFalse($noDoctrine->hasDefinition(EmailsReceivedPersonalDataEraser::class));
    }

    public function testLoadNeedsInstanceSettings(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', []);
        $this->expectException(LogicException::class);
        (new NowoMailKitExtension())->load([], $container);
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
