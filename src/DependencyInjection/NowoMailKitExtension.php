<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\DependencyInjection;

use LogicException;
use Nowo\AuthKitBundle\Mailer\OutboundMailReadyCheckerInterface;
use Nowo\Brand\Contract\BrandAssetReaderInterface;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Security\AccessGuard as InstanceSettingsAccessGuard;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\MailKitBundle\Brand\BrandedEmailHelper;
use Nowo\MailKitBundle\Command\ForgetRecipientCommand;
use Nowo\MailKitBundle\Command\PurgeLegacyResendCommand;
use Nowo\MailKitBundle\Command\PurgeSentMailLogCommand;
use Nowo\MailKitBundle\Command\SendTestMailCommand;
use Nowo\MailKitBundle\Controller\MailKitController;
use Nowo\MailKitBundle\DependencyInjection\Compiler\MailerIntegrationPass;
use Nowo\MailKitBundle\Doctrine\TablePrefixListener;
use Nowo\MailKitBundle\Dsn\DsnHostGuard;
use Nowo\MailKitBundle\Dsn\MailerDsnProviderInterface;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\Dsn\SettingsMailerDsnProvider;
use Nowo\MailKitBundle\EventListener\MailKitAccessListener;
use Nowo\MailKitBundle\EventListener\SettingsChangedListener;
use Nowo\MailKitBundle\Form\LegacyResendSettingsTypeExtension;
use Nowo\MailKitBundle\Form\SentMailFilterType;
use Nowo\MailKitBundle\Integration\AuthKit\MailKitOutboundMailReadyChecker;
use Nowo\MailKitBundle\Integration\UserKit\EmailsReceivedPersonalDataEraser;
use Nowo\MailKitBundle\Integration\UserKit\EmailsReceivedPersonalDataExportProvider;
use Nowo\MailKitBundle\Log\DefaultSentMailPersonalDataSubjectResolver;
use Nowo\MailKitBundle\Log\NullSentMailErasure;
use Nowo\MailKitBundle\Log\NullSentMailPersonalDataExporter;
use Nowo\MailKitBundle\Log\SentMailCsvExporter;
use Nowo\MailKitBundle\Log\SentMailErasureInterface;
use Nowo\MailKitBundle\Log\SentMailPersonalDataExporterInterface;
use Nowo\MailKitBundle\Log\SentMailPersonalDataSubjectResolverInterface;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Log\SentMailRepository;
use Nowo\MailKitBundle\Mailer\MailKitMailer;
use Nowo\MailKitBundle\Mailer\RuntimeTransport;
use Nowo\MailKitBundle\NowoMailKitBundle;
use Nowo\MailKitBundle\Readiness\OutboundMailReadiness;
use Nowo\MailKitBundle\Readiness\OutboundMailReadinessInterface;
use Nowo\MailKitBundle\Resend\ResendApiChecker;
use Nowo\MailKitBundle\Routing\MailKitRouteLoader;
use Nowo\MailKitBundle\Security\AccessGuard;
use Nowo\MailKitBundle\Security\AllowAllMailKitAccessChecker;
use Nowo\MailKitBundle\Security\ConfigurableMailKitAccessChecker;
use Nowo\MailKitBundle\Security\MailKitAccessCheckerInterface;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Nowo\MailKitBundle\Settings\LegacyResendSettings;
use Nowo\MailKitBundle\Settings\MailerSettingsSection;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\TestMail\TestMailGuard;
use Nowo\MailKitBundle\TestMail\TestMailSender;
use Nowo\MailKitBundle\Twig\MailKitBrandTwigExtension;
use Nowo\MailKitBundle\Twig\MailKitTwigExtension;
use Nowo\MailKitBundle\Ui\AdminPages;
use Nowo\MailKitBundle\Ui\MailKitPages;
use Nowo\MailKitBundle\Ui\SettingsActions;
use Nowo\MailKitBundle\Validator\DeliverableSenderValidator;
use Nowo\MailKitBundle\Validator\MailerDsnConstraintValidator;
use Nowo\MailKitBundle\Validator\ResendApiKeyAcceptedValidator;
use Nowo\OutboundUrlGuardBundle\Dns\HostnameDnsLookup;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

use function array_key_exists;
use function implode;
use function interface_exists;
use function is_array;
use function is_bool;
use function is_string;
use function sprintf;

/**
 * Loads `nowo_mail_kit`: the Instance Settings sections, DSN / sender validation, the runtime transport and mailer
 * decorators, readiness (Twig, AuthKit adapter), test e-mail, the sent-mail log and (when enabled) the admin UI.
 *
 * 1.6 (architecture bar rule 8): Instance Settings is optional when the host provides both configuration ports
 * (`mailer.dsn_provider` and `mailer.sender_provider`). Without it the settings section, its change listener and the
 * settings actions are not registered; with `log.enabled: false` the bundle needs no Doctrine either.
 */
final class NowoMailKitExtension extends Extension implements PrependExtensionInterface
{
    /** Service id of the AuthKit adapter. */
    public const AUTH_KIT_CHECKER_ID = 'nowo_mail_kit.outbound_mail_ready_checker';

    /** Service id of the test e-mail rate limiter factory. */
    public const LIMITER_ID = 'nowo_mail_kit.test_email.limiter';

    /** Container parameter: true when the configuration is read from Instance Settings (1.6). */
    public const INSTANCE_SETTINGS_PARAMETER = 'nowo_mail_kit.instance_settings';

    /**
     * `nowo_mail_kit`.
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * Prepends the Doctrine mapping of the log entities (unless `log.map_entities: false`, 1.6), the FormKit profile and
     * the AuthKit mail-ready checker.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('doctrine') && $this->mapEntitiesInRawConfig($container)) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'NowoMailKitBundle' => [
                            'type'      => 'attribute',
                            'dir'       => __DIR__ . '/../Entity',
                            'prefix'    => 'Nowo\MailKitBundle\Entity',
                            'alias'     => 'NowoMailKit',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
        }

        $this->prependFormKitProfile($container);
        $this->assertNoLegacyResendArea($container);

        if ($container->hasExtension('nowo_auth_kit') && interface_exists(OutboundMailReadyCheckerInterface::class)) {
            // A host value of nowo_auth_kit.outbound_mail_ready_checker (loaded after the prepend) wins.
            $container->prependExtensionConfig('nowo_auth_kit', ['outbound_mail_ready_checker' => self::AUTH_KIT_CHECKER_ID]);
        }
    }

    /**
     * `log.map_entities` of the unprocessed configuration (last value wins; default true, also with `log.enabled:
     * false`, so tables written while the log was on stay mapped). The prepend runs before the configuration is
     * processed; a non-literal value keeps the mapping and is rejected later ({@see self::assertLiteralBooleans()}).
     */
    private function mapEntitiesInRawConfig(ContainerBuilder $container): bool
    {
        $map = true;
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $config) {
            if (is_array($config['log'] ?? null) && array_key_exists('map_entities', $config['log']) && is_bool($config['log']['map_entities'])) {
                $map = $config['log']['map_entities'];
            }
        }

        return $map;
    }

    /**
     * Since 1.3 the Resend fields and actions belong to the `mailer` area: an Instance Settings
     * `security.area_roles.resend` entry would be silently ignored (audit CU-L1), so with `resend.enabled` the build
     * fails until the host merges it into `mailer` (or removes it).
     */
    private function assertNoLegacyResendArea(ContainerBuilder $container): void
    {
        $resend = false;
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $config) {
            if (is_array($config['resend'] ?? null) && array_key_exists('enabled', $config['resend'])) {
                $resend = $config['resend']['enabled'] === true;
            }
        }
        if (!$resend) {
            return;
        }
        foreach ($container->getExtensionConfig('nowo_instance_settings') as $config) {
            $areas = is_array($config['security'] ?? null) && is_array($config['security']['area_roles'] ?? null) ? $config['security']['area_roles'] : [];
            if (array_key_exists(LegacyResendSettings::SECTION, $areas)) {
                throw new LogicException('NowoMailKitBundle 1.3: nowo_instance_settings.security.area_roles.resend is no longer used — the Resend API key, sender and actions are part of the "mailer" section since 1.3. Merge those roles into area_roles.mailer (or remove the "resend" entry) so they keep protecting the Resend settings; see UPGRADING "To 1.3.0".');
            }
        }
    }

    /**
     * Registers the services from the processed configuration.
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $this->assertLiteralBooleans($config);

        /** @var array{mailer: array{decorate: bool, dsn_provider: string|null, sender_provider: string|null, fallback_counts_as_ready: bool}, dsn: array{allowed_schemes: list<string>, allowed_hosts: list<string>, allow_private_hosts: bool, allow_insecure_tls: bool, resolve_dns: bool, dns_timeout: float}, sender: array{default_from: string|null, default_from_name: string|null, require_public_domain: bool}, resend: array{enabled: bool, api_timeout: float}, bootstrap: array{mailer_dsn: string|null, resend_api_key: string|null}, test_email: array{rate_limit: int, rate_interval: string}, log: array{enabled: bool, map_entities: bool, store_bodies: bool, retention_days: int, purge_batch_size: int}, personal_data: array{enabled: bool, subject_resolver: string|null}, doctrine: array{table_prefix: string, entity_manager: string|null}, web_ui: array{enabled: bool, path_prefix: string, layout_template: string|null, templates: array<string, string>, page_size: int, export: array{enabled: bool, max_rows: int, access_roles: list<string>, include_bodies: bool, body_batch_bytes: int}}, security: array{access_roles: list<string>, access_checker: string|null, allow_unauthenticated: bool}, brand: array{mode: string, name: string|null, colors: array<string, string>, max_image_bytes: int, max_total_bytes: int, max_image_side: int}} $config */
        $webUi = $config['web_ui'];

        $settings = $this->hasBundle($container, 'NowoInstanceSettingsBundle') || $container->hasExtension('nowo_instance_settings');
        if (!$settings) {
            $this->assertHostProviders($config);
        }
        if ($webUi['enabled'] && !$config['security']['allow_unauthenticated'] && !$this->hasBundle($container, 'SecurityBundle') && !$container->hasExtension('security')) {
            throw new LogicException('NowoMailKitBundle: the admin UI requires symfony/security-bundle (or security.allow_unauthenticated: true in dev / demo only).');
        }

        $container->setParameter('nowo_mail_kit.doctrine.table_prefix', $config['doctrine']['table_prefix']);
        $container->setParameter('nowo_mail_kit.web_ui.enabled', $webUi['enabled']);
        $container->setParameter('nowo_mail_kit.web_ui.path_prefix', $webUi['path_prefix']);
        $container->setParameter('nowo_mail_kit.log.enabled', $config['log']['enabled']);
        $container->setParameter('nowo_mail_kit.log.retention_days', $config['log']['retention_days']);
        $container->setParameter('nowo_mail_kit.resend.enabled', $config['resend']['enabled']);
        $container->setParameter('nowo_mail_kit.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);
        $container->setParameter(self::INSTANCE_SETTINGS_PARAMETER, $settings);

        if ($settings) {
            $this->registerSettings($container, $config);
        }
        $this->registerValidation($container, $config['dsn']);
        $hasDoctrine = $this->hasBundle($container, 'DoctrineBundle') || $container->hasExtension('doctrine');
        $this->registerLog($container, $config['log'], $config['doctrine'], $hasDoctrine);
        $this->registerPersonalData($container, $config['personal_data'], ($config['log']['enabled'] || $hasDoctrine) && $config['log']['map_entities']);
        $this->registerMailer($container, $config, $settings);
        $this->registerTestMail($container, $config, $settings);
        $this->registerWebUi($container, $config, $settings);
        $this->registerBrand($container, $config['brand']);
    }

    /**
     * Without Instance Settings (1.6) every configuration value must come from a host port: the DSN provider and the
     * sender provider. The Resend settings live in the Instance Settings `mailer` section, so `resend.enabled` needs it.
     *
     * @param array{mailer: array{dsn_provider: string|null, sender_provider: string|null}, resend: array{enabled: bool}} $config
     */
    private function assertHostProviders(array $config): void
    {
        $missing = [];
        foreach (['dsn_provider', 'sender_provider'] as $option) {
            $id = $config['mailer'][$option];
            if (!is_string($id) || $id === '') {
                $missing[] = 'nowo_mail_kit.mailer.' . $option;
            }
        }
        if ($missing !== []) {
            throw new LogicException(sprintf('NowoMailKitBundle reads its configuration from nowo-tech/instance-settings-bundle by default: register Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle in config/bundles.php, or set %s to your own service ids (configuration from host ports, no Instance Settings).', implode(' and ', $missing)));
        }
        if ($config['resend']['enabled']) {
            throw new LogicException('NowoMailKitBundle: nowo_mail_kit.resend.enabled requires nowo-tech/instance-settings-bundle (the Resend key and sender are fields of its "mailer" section). Register the bundle or set resend.enabled: false.');
        }
    }

    /**
     * Spec 220 S7: the branded e-mail helper and its Twig functions, always registered (the layout works without a
     * brand). With `brand.mode: auto` and nowo-tech/brand-contracts installed, the brand provider and asset reader are
     * optional references; without them the helper leaves every e-mail untouched and the layout prints the
     * configured fallback.
     *
     * @param array{mode: string, name: string|null, colors: array<string, string>, max_image_bytes: int, max_total_bytes: int, max_image_side: int} $brand
     */
    private function registerBrand(ContainerBuilder $container, array $brand): void
    {
        $auto = $brand['mode'] === Configuration::BRAND_AUTO && interface_exists(BrandProviderInterface::class);

        $container->setDefinition(BrandedEmailHelper::class, (new Definition(BrandedEmailHelper::class, [
            '$defaultName'   => $brand['name'],
            '$defaultColors' => $brand['colors'],
            '$provider'      => $auto ? new Reference(BrandProviderInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE) : null,
            '$reader'        => $auto ? new Reference(BrandAssetReaderInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE) : null,
            '$maxImageBytes' => $brand['max_image_bytes'],
            '$maxTotalBytes' => $brand['max_total_bytes'],
            '$maxImageSide'  => $brand['max_image_side'],
            '$logger'        => new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true));
        $container->setDefinition(MailKitBrandTwigExtension::class, (new Definition(MailKitBrandTwigExtension::class, [new Reference(BrandedEmailHelper::class)]))
            ->addTag('twig.extension'));
    }

    /**
     * Default FormKit profile of the bundle forms: bundle translation domain, no auto help / placeholder keys, no
     * framework classes (the UiKit scaffolds paint the fields through their slots, REQ-UI-003).
     *
     * @return array<string, mixed>
     */
    public static function formKitProfile(): array
    {
        return [
            'alias'              => NowoMailKitBundle::FORM_KIT_PROFILE,
            'translation_domain' => NowoMailKitBundle::TRANSLATION_DOMAIN,
            'auto_help'          => false,
            'auto_placeholder'   => false,
            'defaults'           => ['attr' => [], 'row_attr' => []],
            'field_types'        => [],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerSettings(ContainerBuilder $container, array $config): void
    {
        /** @var array{resend: array{enabled: bool}, bootstrap: array{mailer_dsn: string|null, resend_api_key: string|null}, sender: array{default_from: string|null, default_from_name: string|null, require_public_domain: bool}, web_ui: array{enabled: bool}} $config */
        $actions   = $config['web_ui']['enabled'] ? new Reference(SettingsActions::class) : null;
        $readiness = new ServiceClosureArgument(new Reference(OutboundMailReadiness::class));

        $resend = $config['resend']['enabled'];
        // 1.3: the 1.2 `resend` rows, read until they are seeded into the mailer section (kernel.reset clears the memo).
        if ($resend) {
            $container->setDefinition(LegacyResendSettings::class, (new Definition(LegacyResendSettings::class, [
                new Reference(SettingValueRepositoryInterface::class),
                new ServiceClosureArgument(new Reference(SettingsSectionRegistry::class)),
            ]))
                ->addTag('kernel.reset', ['method' => 'reset']));
            // The mailer page shows the live 1.2 Resend values until they are migrated (pre-selected provider, kept key).
            $container->setDefinition(LegacyResendSettingsTypeExtension::class, (new Definition(LegacyResendSettingsTypeExtension::class, [
                new Reference(MailSettings::class),
                new Reference(LegacyResendSettings::class),
            ]))->addTag('form.type_extension', ['extended_type' => SettingsSectionType::class]));
            $container->setDefinition(PurgeLegacyResendCommand::class, (new Definition(PurgeLegacyResendCommand::class, [
                new Reference(SettingValueRepositoryInterface::class),
                new Reference(InstanceSettingsInterface::class),
                new Reference(LegacyResendSettings::class),
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]))->addTag('console.command', ['command' => PurgeLegacyResendCommand::NAME]));
        }

        $container->setDefinition(MailSettings::class, new Definition(MailSettings::class, [
            new Reference(InstanceSettingsInterface::class),
            $resend,
            $resend ? new Reference(LegacyResendSettings::class) : null,
        ]));

        $container->setDefinition(MailerSettingsSection::class, (new Definition(MailerSettingsSection::class, [
            $config['sender']['default_from'],
            $config['sender']['default_from_name'],
            $config['sender']['require_public_domain'],
            $actions,
            $config['bootstrap']['mailer_dsn'],
            $readiness,
            $resend,
            $resend ? $config['bootstrap']['resend_api_key'] : null,
            $resend ? new Reference(LegacyResendSettings::class) : null,
            $resend ? new ServiceClosureArgument(new Reference(MailSettings::class)) : null,
        ]))->addTag(NowoInstanceSettingsBundle::SECTION_TAG, ['priority' => 50]));
    }

    /**
     * @param array{allowed_schemes: list<string>, allowed_hosts: list<string>, allow_private_hosts: bool, allow_insecure_tls: bool, resolve_dns: bool, dns_timeout: float} $dsn
     */
    private function registerValidation(ContainerBuilder $container, array $dsn): void
    {
        $container->setDefinition('nowo_mail_kit.dns_lookup', new Definition(HostnameDnsLookup::class, [$dsn['dns_timeout']]));
        $container->setDefinition(DsnHostGuard::class, new Definition(DsnHostGuard::class, [
            Configuration::lower($dsn['allowed_hosts']),
            $dsn['allow_private_hosts'],
            $dsn['resolve_dns'],
            new Reference('nowo_mail_kit.dns_lookup'),
        ]));
        $container->setDefinition(MailerDsnValidator::class, (new Definition(MailerDsnValidator::class, [
            Configuration::lower($dsn['allowed_schemes']),
            new Reference(DsnHostGuard::class),
            new Reference('mailer.transport_factory', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $dsn['allow_insecure_tls'],
        ]))->setPublic(true));

        $container->setDefinition(MailerDsnConstraintValidator::class, (new Definition(MailerDsnConstraintValidator::class, [new Reference(MailerDsnValidator::class)]))
            ->addTag('validator.constraint_validator'));
        $container->setDefinition(DeliverableSenderValidator::class, (new Definition(DeliverableSenderValidator::class))
            ->addTag('validator.constraint_validator'));
    }

    /**
     * The recorder (erasure, retention and the forget / purge commands) is registered whenever Doctrine is: with
     * `log.enabled: false` it records nothing but still erases and purges the rows written while the log was on (1.6,
     * LL-MK-M1). Only without Doctrine is the erasure port a no-op.
     *
     * @param array{enabled: bool, map_entities: bool, store_bodies: bool, retention_days: int, purge_batch_size: int} $log
     * @param array{table_prefix: string, entity_manager: string|null} $doctrine
     * @param bool $hasDoctrine DoctrineBundle registered
     */
    private function registerLog(ContainerBuilder $container, array $log, array $doctrine, bool $hasDoctrine): void
    {
        $container->setDefinition(TablePrefixListener::class, (new Definition(TablePrefixListener::class, [$doctrine['table_prefix']]))
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));

        if (!$log['enabled'] && !$hasDoctrine) {
            $container->setDefinition(NullSentMailErasure::class, new Definition(NullSentMailErasure::class));
            $container->setAlias(SentMailErasureInterface::class, NullSentMailErasure::class)->setPublic(true);
            $container->setDefinition(NullSentMailPersonalDataExporter::class, new Definition(NullSentMailPersonalDataExporter::class));
            $container->setAlias(SentMailPersonalDataExporterInterface::class, NullSentMailPersonalDataExporter::class)->setPublic(true);

            return;
        }

        $container->setAlias(SentMailErasureInterface::class, SentMailRecorder::class)->setPublic(true);
        $container->setAlias(SentMailPersonalDataExporterInterface::class, SentMailRecorder::class)->setPublic(true);
        $container->setDefinition(SentMailRecorder::class, (new Definition(SentMailRecorder::class, [
            new Reference('doctrine'),
            $doctrine['entity_manager'],
            $doctrine['table_prefix'],
            $log['store_bodies'],
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            // LL-MK-L3: missing tables are "nothing stored" only when the host opted out of the mapping.
            !$log['map_entities'],
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true));
        if ($log['enabled']) {
            $container->setDefinition(SentMailRepository::class, (new Definition(SentMailRepository::class, [
                new Reference('doctrine'),
                $doctrine['entity_manager'],
            ]))->setPublic(true));
        }

        $container->setDefinition(PurgeSentMailLogCommand::class, (new Definition(PurgeSentMailLogCommand::class, [
            new Reference(SentMailRecorder::class),
            $log['retention_days'],
            $log['purge_batch_size'],
            new Reference('clock'),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->addTag('console.command', ['command' => PurgeSentMailLogCommand::NAME]));
        $container->setDefinition(ForgetRecipientCommand::class, (new Definition(ForgetRecipientCommand::class, [
            new Reference(SentMailRecorder::class),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->addTag('console.command', ['command' => ForgetRecipientCommand::NAME]));
    }

    /**
     * GDPR ports (1.7): the subject resolver SPI always; the UserKit eraser and export provider (`emails_received`) only
     * with UserKit installed, the log entities mapped (the recorder registered) and `personal_data.enabled`.
     *
     * @param array{enabled: bool, subject_resolver: string|null} $personalData
     * @param bool $logMapped the recorder is registered and the log entities are mapped
     */
    private function registerPersonalData(ContainerBuilder $container, array $personalData, bool $logMapped): void
    {
        $container->setDefinition(DefaultSentMailPersonalDataSubjectResolver::class, new Definition(DefaultSentMailPersonalDataSubjectResolver::class));
        $resolver = is_string($personalData['subject_resolver']) && $personalData['subject_resolver'] !== '' ? $personalData['subject_resolver'] : DefaultSentMailPersonalDataSubjectResolver::class;
        $container->setAlias(SentMailPersonalDataSubjectResolverInterface::class, $resolver)->setPublic(true);

        if (!$personalData['enabled'] || !$logMapped || !interface_exists(PersonalDataEraserInterface::class)) {
            return;
        }
        $container->setDefinition(EmailsReceivedPersonalDataEraser::class, (new Definition(EmailsReceivedPersonalDataEraser::class, [
            new Reference(SentMailErasureInterface::class),
            new Reference(SentMailPersonalDataSubjectResolverInterface::class),
        ]))->addTag('nowo_user_kit.personal_data.eraser', ['priority' => EmailsReceivedPersonalDataEraser::PRIORITY]));
        $container->setDefinition(EmailsReceivedPersonalDataExportProvider::class, (new Definition(EmailsReceivedPersonalDataExportProvider::class, [
            new Reference(SentMailPersonalDataExporterInterface::class),
            new Reference(SentMailPersonalDataSubjectResolverInterface::class),
        ]))->addTag('nowo_user_kit.personal_data.export_provider', ['priority' => EmailsReceivedPersonalDataExportProvider::PRIORITY]));
    }

    /**
     * @param array<string, mixed> $config
     * @param bool $settings Instance Settings available (else both providers are host services)
     */
    private function registerMailer(ContainerBuilder $container, array $config, bool $settings): void
    {
        /** @var array{mailer: array{decorate: bool, dsn_provider: string|null, sender_provider: string|null, fallback_counts_as_ready: bool}, log: array{enabled: bool}} $config */
        $mailer   = $config['mailer'];
        $recorder = $config['log']['enabled'] ? new Reference(SentMailRecorder::class) : null;

        if ($settings) {
            $container->setDefinition(SettingsMailerDsnProvider::class, new Definition(SettingsMailerDsnProvider::class, [new Reference(MailSettings::class)]));
        }
        $provider = $mailer['dsn_provider'];
        $container->setAlias(MailerDsnProviderInterface::class, is_string($provider) && $provider !== '' ? $provider : SettingsMailerDsnProvider::class);

        $senderProvider = $mailer['sender_provider'];
        $container->setAlias(SenderProviderInterface::class, is_string($senderProvider) && $senderProvider !== '' ? $senderProvider : MailSettings::class);
        $container->setDefinition(SenderPolicy::class, new Definition(SenderPolicy::class, [new Reference(SenderProviderInterface::class)]));

        $transport = (new Definition(RuntimeTransport::class, [
            new Reference($mailer['decorate'] ? RuntimeTransport::class . '.inner' : 'mailer.transports'),
            new Reference(MailerDsnProviderInterface::class),
            new Reference(MailerDsnValidator::class),
            new Reference('mailer.transport_factory'),
            new Reference(SenderPolicy::class),
            $recorder,
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true)->addTag('kernel.reset', ['method' => 'reset']);
        if ($mailer['decorate']) {
            $transport->setDecoratedService('mailer.transports');
        }
        $container->setDefinition(RuntimeTransport::class, $transport);

        if ($mailer['decorate']) {
            $container->setDefinition(MailerIntegrationPass::MAILER_ID, (new Definition(MailKitMailer::class, [
                '$inner'    => new Reference(MailerIntegrationPass::MAILER_ID . '.inner'),
                '$sender'   => new Reference(SenderPolicy::class),
                '$recorder' => $recorder,
                '$queues'   => false,
            ]))->setDecoratedService('mailer.mailer'));
        }

        $container->setDefinition(OutboundMailReadiness::class, (new Definition(OutboundMailReadiness::class, [
            new Reference(MailerDsnProviderInterface::class),
            new Reference(MailerDsnValidator::class),
            $mailer['fallback_counts_as_ready'],
            is_string($provider) && $provider !== '' ? null : new Reference(MailSettings::class),
        ]))->setPublic(true));
        $container->setAlias(OutboundMailReadinessInterface::class, OutboundMailReadiness::class)->setPublic(true);
        $container->setDefinition(MailKitTwigExtension::class, (new Definition(MailKitTwigExtension::class, [new Reference(OutboundMailReadiness::class)]))
            ->addTag('twig.extension'));
        if ($settings) {
            $container->setDefinition(SettingsChangedListener::class, (new Definition(SettingsChangedListener::class, [
                new Reference(RuntimeTransport::class),
                new Reference(OutboundMailReadiness::class),
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]))->addTag('kernel.event_listener', ['event' => SettingsChangedEvent::class]));
        }

        if (interface_exists(OutboundMailReadyCheckerInterface::class)) {
            $container->setDefinition(self::AUTH_KIT_CHECKER_ID, (new Definition(MailKitOutboundMailReadyChecker::class, [new Reference(OutboundMailReadiness::class)]))
                ->setPublic(true));
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param bool $settings Instance Settings available (else the test sender reads the host sender provider)
     */
    private function registerTestMail(ContainerBuilder $container, array $config, bool $settings): void
    {
        /** @var array{mailer: array{sender_provider: string|null}, test_email: array{rate_limit: int, rate_interval: string}, sender: array{require_public_domain: bool}, resend: array{enabled: bool, api_timeout: float}} $config */
        $hostSender = is_string($config['mailer']['sender_provider']) && $config['mailer']['sender_provider'] !== '';
        $container->setDefinition(TestMailSender::class, (new Definition(TestMailSender::class, [
            new Reference(RuntimeTransport::class),
            $settings ? new Reference(MailSettings::class) : null,
            new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $config['sender']['require_public_domain'],
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            // The test e-mail uses the same sender as real mail (LL-MK-L2): the host port when one is set.
            !$settings || $hostSender ? new Reference(SenderProviderInterface::class) : null,
        ]))->setPublic(true));

        $container->setDefinition(self::LIMITER_ID, new Definition(RateLimiterFactory::class, [
            ['id' => 'nowo_mail_kit_test_email', 'policy' => 'sliding_window', 'limit' => $config['test_email']['rate_limit'], 'interval' => $config['test_email']['rate_interval']],
            new Definition(CacheStorage::class, [new Reference('cache.app')]),
        ]));
        $container->setDefinition(TestMailGuard::class, new Definition(TestMailGuard::class, [new Reference(self::LIMITER_ID)]));

        $container->setDefinition(SendTestMailCommand::class, (new Definition(SendTestMailCommand::class, [
            new Reference(TestMailSender::class),
            new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->addTag('console.command', ['command' => SendTestMailCommand::NAME]));

        if ($config['resend']['enabled']) {
            $container->setDefinition(ResendApiChecker::class, (new Definition(ResendApiChecker::class, [
                new Reference('http_client'),
                $config['resend']['api_timeout'],
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]))->setPublic(true));
            // 1.3 (MK-L1): the admin save checks a Resend key before it goes live.
            $container->setDefinition(ResendApiKeyAcceptedValidator::class, (new Definition(ResendApiKeyAcceptedValidator::class, [
                new Reference(ResendApiChecker::class),
                new Reference(MailSettings::class),
                new Reference(TestMailGuard::class),
                new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]))->addTag('validator.constraint_validator'));
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param bool $settings Instance Settings available (else no settings actions: only the log pages)
     */
    private function registerWebUi(ContainerBuilder $container, array $config, bool $settings): void
    {
        /** @var array{web_ui: array{enabled: bool, path_prefix: string, layout_template: string|null, templates: array<string, string>, page_size: int, export: array{enabled: bool, max_rows: int, access_roles: list<string>, include_bodies: bool, body_batch_bytes: int}}, security: array{access_roles: list<string>, access_checker: string|null, allow_unauthenticated: bool}, log: array{enabled: bool, store_bodies: bool}, resend: array{enabled: bool}} $config */
        $webUi  = $config['web_ui'];
        $export = $webUi['export']['enabled'] && $config['log']['enabled'];

        $container->setDefinition(MailKitRouteLoader::class, (new Definition(MailKitRouteLoader::class, [
            $webUi['enabled'],
            $webUi['path_prefix'],
            $config['log']['enabled'],
            $config['log']['store_bodies'],
            $config['resend']['enabled'],
            null,
            $webUi['export']['enabled'],
            $settings,
        ]))->addTag('routing.loader'));

        if (!$webUi['enabled']) {
            return;
        }

        $this->registerAccess($container, $config['security'], Configuration::roles($webUi['export']['access_roles']));

        if ($export) {
            $container->setDefinition(SentMailCsvExporter::class, (new Definition(SentMailCsvExporter::class, [
                new Reference(SentMailRepository::class),
                $webUi['export']['max_rows'],
                $webUi['export']['include_bodies'] && $config['log']['store_bodies'],
                new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                new Reference('clock'),
                SentMailCsvExporter::BODY_BATCH,
                $webUi['export']['body_batch_bytes'],
            ]))->setPublic(true));
        }

        $container->setDefinition(SettingsActions::class, new Definition(SettingsActions::class, [
            new Reference('router'),
            new Reference(CsrfOnlyFormFactory::class),
        ]));
        $container->setDefinition(SentMailFilterType::class, (new Definition(SentMailFilterType::class))->addTag('form.type'));
        $container->setDefinition(MailKitPages::class, new Definition(MailKitPages::class, [$webUi['layout_template'], $webUi['templates']]));
        $container->setDefinition(AdminPages::class, new Definition(AdminPages::class, [new Reference('router'), new Reference(MailKitPages::class)]));

        $container->setDefinition(MailKitAccessListener::class, (new Definition(MailKitAccessListener::class, [
            new Reference(AccessGuard::class),
            $webUi['path_prefix'],
        ]))->addTag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onKernelRequest', 'priority' => MailKitAccessListener::PRIORITY]));

        // REQ-SF-004 rule 7: not autoconfigured (no `routing.controller` tag from #[Route]); the routes come only from
        // the `nowo_mail_kit` loader. Autowired only for AbstractController::setContainer().
        $container->setDefinition(MailKitController::class, (new Definition(MailKitController::class, [
            '$guard'         => new Reference(AccessGuard::class),
            '$csrfForms'     => new Reference(CsrfOnlyFormFactory::class),
            '$pages'         => new Reference(MailKitPages::class),
            '$adminPages'    => new Reference(AdminPages::class),
            '$readiness'     => new Reference(OutboundMailReadiness::class),
            '$testMail'      => new Reference(TestMailSender::class),
            '$testMailGuard' => new Reference(TestMailGuard::class),
            '$settings'      => $settings ? new Reference(MailSettings::class) : null,
            '$repository'    => $config['log']['enabled'] ? new Reference(SentMailRepository::class) : null,
            '$resendChecker' => $config['resend']['enabled'] ? new Reference(ResendApiChecker::class) : null,
            '$exporter'      => $export ? new Reference(SentMailCsvExporter::class) : null,
            '$pageSize'      => $webUi['page_size'],
            '$translator'    => new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            '$clock'         => new Reference('clock'),
        ]))
            ->setAutowired(true)
            ->setAutoconfigured(false)
            ->setPublic(true)
            ->addTag('controller.service_arguments')
            ->addTag('container.service_subscriber'));
    }

    /**
     * @param array{access_roles: list<string>, access_checker: string|null, allow_unauthenticated: bool} $security
     * @param list<string> $exportRoles `web_ui.export.access_roles`
     */
    private function registerAccess(ContainerBuilder $container, array $security, array $exportRoles): void
    {
        $customId = $security['access_checker'];
        if (is_string($customId) && $customId !== '') {
            $container->setAlias(MailKitAccessCheckerInterface::class, $customId);
        } elseif ($security['allow_unauthenticated']) {
            $container->setDefinition(AllowAllMailKitAccessChecker::class, new Definition(AllowAllMailKitAccessChecker::class));
            $container->setAlias(MailKitAccessCheckerInterface::class, AllowAllMailKitAccessChecker::class);
        } else {
            $container->setDefinition(ConfigurableMailKitAccessChecker::class, new Definition(ConfigurableMailKitAccessChecker::class, [
                new Reference('security.authorization_checker'),
                Configuration::roles($security['access_roles']),
            ]));
            $container->setAlias(MailKitAccessCheckerInterface::class, ConfigurableMailKitAccessChecker::class);
        }

        $container->setDefinition(AccessGuard::class, new Definition(AccessGuard::class, [
            new Reference(MailKitAccessCheckerInterface::class),
            new Reference(InstanceSettingsAccessGuard::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $exportRoles,
            new Reference('security.authorization_checker', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));
    }

    /**
     * Registers the FormKit profile {@see NowoMailKitBundle::FORM_KIT_PROFILE} unless the host defines it.
     */
    private function prependFormKitProfile(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        foreach ($container->getExtensionConfig('nowo_form_kit') as $config) {
            $profiles = $config['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists(NowoMailKitBundle::FORM_KIT_PROFILE, $profiles)) {
                return;
            }
        }

        $container->prependExtensionConfig('nowo_form_kit', [
            'profiles' => [NowoMailKitBundle::FORM_KIT_PROFILE => self::formKitProfile()],
        ]);
    }

    /**
     * Security / feature switches must be literal booleans (an env placeholder would read as true at compile time).
     *
     * @param array<string, mixed> $config
     */
    private function assertLiteralBooleans(array $config): void
    {
        /** @var array<string, array<string, mixed>> $config */
        $switches = [
            'mailer.decorate'                => $config['mailer']['decorate'] ?? null,
            'dsn.allow_private_hosts'        => $config['dsn']['allow_private_hosts'] ?? null,
            'dsn.allow_insecure_tls'         => $config['dsn']['allow_insecure_tls'] ?? null,
            'resend.enabled'                 => $config['resend']['enabled'] ?? null,
            'log.enabled'                    => $config['log']['enabled'] ?? null,
            'log.map_entities'               => $config['log']['map_entities'] ?? null,
            'log.store_bodies'               => $config['log']['store_bodies'] ?? null,
            'personal_data.enabled'          => $config['personal_data']['enabled'] ?? null,
            'web_ui.enabled'                 => $config['web_ui']['enabled'] ?? null,
            'web_ui.export.enabled'          => $config['web_ui']['export']['enabled'] ?? null,
            'web_ui.export.include_bodies'   => $config['web_ui']['export']['include_bodies'] ?? null,
            'security.allow_unauthenticated' => $config['security']['allow_unauthenticated'] ?? null,
        ];
        foreach ($switches as $path => $value) {
            if (!is_bool($value)) {
                throw new InvalidConfigurationException(sprintf('nowo_mail_kit.%s must be a literal boolean (true or false); environment placeholders such as %%env(bool:...)%% are not supported for these switches.', $path));
            }
        }
    }

    private function hasBundle(ContainerBuilder $container, string $name): bool
    {
        $bundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : [];

        return is_array($bundles) && array_key_exists($name, $bundles);
    }
}
