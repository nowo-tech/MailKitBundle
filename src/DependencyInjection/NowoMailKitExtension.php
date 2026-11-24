<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\DependencyInjection;

use Doctrine\ORM\EntityManagerInterface;
use finfo;
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
use Nowo\MailKitBundle\Doctrine\MailKitExactKeyColumns;
use Nowo\MailKitBundle\Doctrine\TablePrefixListener;
use Nowo\MailKitBundle\Dsn\DsnHostGuard;
use Nowo\MailKitBundle\Dsn\MailerDsnProviderInterface;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\Dsn\SettingsMailerDsnProvider;
use Nowo\MailKitBundle\EventListener\LegacyResendSettingsResetListener;
use Nowo\MailKitBundle\EventListener\MailKitAccessListener;
use Nowo\MailKitBundle\EventListener\MailKitConflictListener;
use Nowo\MailKitBundle\EventListener\SettingsChangedListener;
use Nowo\MailKitBundle\Form\LegacyResendSettingsTypeExtension;
use Nowo\MailKitBundle\Form\SentMailFilterType;
use Nowo\MailKitBundle\Graph\ConfiguredGraphMailboxProvider;
use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GraphMailboxProviderInterface;
use Nowo\MailKitBundle\Graph\GraphTokenProvider;
use Nowo\MailKitBundle\Graph\Inbound\Command\GraphSchemaCommand;
use Nowo\MailKitBundle\Graph\Inbound\Command\GraphSubscriptionsCommand;
use Nowo\MailKitBundle\Graph\Inbound\Command\GraphSweepCommand;
use Nowo\MailKitBundle\Graph\Inbound\ConfiguredInboundProfiles;
use Nowo\MailKitBundle\Graph\Inbound\GraphDeltaSweeper;
use Nowo\MailKitBundle\Graph\Inbound\GraphInboundHandlerInterface;
use Nowo\MailKitBundle\Graph\Inbound\GraphInboundProcessor;
use Nowo\MailKitBundle\Graph\Inbound\GraphInboundProfilesProviderInterface;
use Nowo\MailKitBundle\Graph\Inbound\GraphMessageNormalizer;
use Nowo\MailKitBundle\Graph\Inbound\GraphSubscriptionManager;
use Nowo\MailKitBundle\Graph\Inbound\InboundHtmlSanitizer;
use Nowo\MailKitBundle\Graph\Inbound\InboundLimits;
use Nowo\MailKitBundle\Graph\Inbound\Message\FetchGraphMessage;
use Nowo\MailKitBundle\Graph\Inbound\Message\GraphInboundMessageHandler;
use Nowo\MailKitBundle\Graph\Inbound\Message\RenewGraphSubscriptions;
use Nowo\MailKitBundle\Graph\Inbound\Message\SweepGraphMailbox;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundSchemaListener;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Nowo\MailKitBundle\Graph\Inbound\Webhook\GraphWebhookController;
use Nowo\MailKitBundle\Graph\Inbound\Webhook\GraphWebhookRouteLoader;
use Nowo\MailKitBundle\Graph\Send\DeleteGraphDraft;
use Nowo\MailKitBundle\Graph\Send\DeleteGraphDraftHandler;
use Nowo\MailKitBundle\Graph\Send\GraphMailSender;
use Nowo\MailKitBundle\Graph\Send\GraphSendLimits;
use Nowo\MailKitBundle\Graph\Transport\GraphTransportFactory;
use Nowo\MailKitBundle\Integration\AuthKit\MailKitOutboundMailReadyChecker;
use Nowo\MailKitBundle\Integration\UserKit\EmailsReceivedPersonalDataEraser;
use Nowo\MailKitBundle\Integration\UserKit\EmailsReceivedPersonalDataExportProvider;
use Nowo\MailKitBundle\Integration\UserKit\EmailTrackingPersonalDataEraser;
use Nowo\MailKitBundle\Integration\UserKit\EmailTrackingPersonalDataExportProvider;
use Nowo\MailKitBundle\Log\DefaultSentMailPersonalDataSubjectResolver;
use Nowo\MailKitBundle\Log\NullSentMailErasure;
use Nowo\MailKitBundle\Log\NullSentMailPersonalDataExporter;
use Nowo\MailKitBundle\Log\SentMailCsvExporter;
use Nowo\MailKitBundle\Log\SentMailErasureInterface;
use Nowo\MailKitBundle\Log\SentMailPersonalDataEraserInterface;
use Nowo\MailKitBundle\Log\SentMailPersonalDataExporterInterface;
use Nowo\MailKitBundle\Log\SentMailPersonalDataSubjectResolverInterface;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Log\SentMailRepository;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxAttachmentReader;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSyncEngine;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSyncOptions;
use Nowo\MailKitBundle\Mailbox\Graph\GraphMailboxGateway;
use Nowo\MailKitBundle\Mailbox\Html\MailHtmlSanitizer;
use Nowo\MailKitBundle\Mailbox\Html\RemoteImages;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Command\MailboxSchemaCommand;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Command\MailboxStatusCommand;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Command\MailboxSubscriptionsCommand;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Command\MailboxSweepCommand;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\FetchMailboxMessage;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\MailboxMessageHandler;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\RenewMailboxSubscriptions;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\SyncMailboxFolder;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Store\MailboxSchemaListener;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Store\MailboxStore;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Webhook\MailboxGraphWebhookController;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Webhook\MailboxWebhookRouteLoader;
use Nowo\MailKitBundle\Mailbox\Port\KeepAllExclusionPolicy;
use Nowo\MailKitBundle\Mailbox\Port\MailboxCallObserverInterface;
use Nowo\MailKitBundle\Mailbox\Port\MailboxDirectoryInterface;
use Nowo\MailKitBundle\Mailbox\Port\MailboxExclusionPolicyInterface;
use Nowo\MailKitBundle\Mailbox\Port\MailboxMessageSinkInterface;
use Nowo\MailKitBundle\Mailbox\Port\MailboxTokenProviderInterface;
use Nowo\MailKitBundle\Mailbox\Port\NullMailboxCallObserver;
use Nowo\MailKitBundle\Mailbox\Service\MailboxAttachmentReaderInterface;
use Nowo\MailKitBundle\Mailbox\Service\MailboxSyncInterface;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
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
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Nowo\MailKitBundle\Settings\LegacyResendSettings;
use Nowo\MailKitBundle\Settings\MailerSettingsSection;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\TestMail\TestMailGuard;
use Nowo\MailKitBundle\TestMail\TestMailSender;
use Nowo\MailKitBundle\Tracking\Command\TrackingPurgeCommand;
use Nowo\MailKitBundle\Tracking\Command\TrackingSchemaCommand;
use Nowo\MailKitBundle\Tracking\ConfiguredTrackableLinkFilter;
use Nowo\MailKitBundle\Tracking\CredentialLinkDetector;
use Nowo\MailKitBundle\Tracking\DefaultTrackingRecipientResolver;
use Nowo\MailKitBundle\Tracking\EmailLinkRewriterInterface;
use Nowo\MailKitBundle\Tracking\EmailTracker;
use Nowo\MailKitBundle\Tracking\EmailTrackingPersonalDataInterface;
use Nowo\MailKitBundle\Tracking\EmailTrackingPolicyInterface;
use Nowo\MailKitBundle\Tracking\EmailTrackingReaderInterface;
use Nowo\MailKitBundle\Tracking\HtmlLinkTracker;
use Nowo\MailKitBundle\Tracking\Http\TrackingPixelController;
use Nowo\MailKitBundle\Tracking\Http\TrackingRouteLoader;
use Nowo\MailKitBundle\Tracking\Message\RecordMailOpen;
use Nowo\MailKitBundle\Tracking\Message\RecordMailOpenHandler;
use Nowo\MailKitBundle\Tracking\NullEmailLinkRewriter;
use Nowo\MailKitBundle\Tracking\OpenClassifier;
use Nowo\MailKitBundle\Tracking\OpenRecorder;
use Nowo\MailKitBundle\Tracking\Store\TrackingSchemaListener;
use Nowo\MailKitBundle\Tracking\Store\TrackingStore;
use Nowo\MailKitBundle\Tracking\TrackableLinkFilterInterface;
use Nowo\MailKitBundle\Tracking\TrackingBaseUrl;
use Nowo\MailKitBundle\Tracking\TrackingRecipientResolverInterface;
use Nowo\MailKitBundle\Tracking\TrackingTokenSigner;
use Nowo\MailKitBundle\Twig\MailKitBrandTwigExtension;
use Nowo\MailKitBundle\Twig\MailKitTwigExtension;
use Nowo\MailKitBundle\Ui\AdminPages;
use Nowo\MailKitBundle\Ui\MailKitPages;
use Nowo\MailKitBundle\Ui\SettingsActions;
use Nowo\MailKitBundle\Validator\DeliverableSenderValidator;
use Nowo\MailKitBundle\Validator\MailerDsnConstraintValidator;
use Nowo\MailKitBundle\Validator\ResendApiKeyAcceptedValidator;
use Nowo\OutboundUrlGuardBundle\Dns\HostnameDnsLookup;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

use function array_key_exists;
use function class_exists;
use function implode;
use function interface_exists;
use function is_array;
use function is_bool;
use function is_string;
use function sprintf;
use function str_contains;
use function strlen;

/**
 * Loads `nowo_mail_kit`: the Instance Settings sections, DSN / sender validation, the runtime transport and mailer
 * decorators, readiness (Twig, AuthKit adapter), test e-mail, the sent-mail log and (when enabled) the admin UI.
 *
 * 1.6 (architecture bar rule 8): Instance Settings is optional when the host provides both configuration ports
 * (`mailer.dsn_provider` and `mailer.sender_provider`). Without it the settings section, its change listener and the
 * settings actions are not registered; with `log.enabled: false` the bundle needs no Doctrine ORM either. Since 2.0
 * Instance Settings, DoctrineEncrypt and the ORM are suggested, not required: every service that uses one of them is
 * registered only when it is installed, and a configuration that needs a missing one fails the build.
 */
final class NowoMailKitExtension extends Extension implements PrependExtensionInterface
{
    /** Service id of the AuthKit adapter. */
    public const AUTH_KIT_CHECKER_ID = 'nowo_mail_kit.outbound_mail_ready_checker';

    /** Service id of the test e-mail rate limiter factory. */
    public const LIMITER_ID = 'nowo_mail_kit.test_email.limiter';

    /** Service id of the Graph webhook rate limiter factory of verified requests (1.9). */
    public const GRAPH_WEBHOOK_LIMITER_ID = 'nowo_mail_kit.graph.webhook_limiter';

    /** Service id of the per-client-IP rate limiter factory of unverified webhook requests (1.9). */
    public const GRAPH_WEBHOOK_CLIENT_LIMITER_ID = 'nowo_mail_kit.graph.webhook_client_limiter';

    /** Service id of the delegated mailboxes' webhook rate limiter factory of verified requests (2.5.0). */
    public const MAILBOX_WEBHOOK_LIMITER_ID = 'nowo_mail_kit.mailbox.webhook_limiter';

    /** Service id of the delegated mailboxes' per-client-IP webhook rate limiter factory (2.5.0). */
    public const MAILBOX_WEBHOOK_CLIENT_LIMITER_ID = 'nowo_mail_kit.mailbox.webhook_client_limiter';

    /** Service id of the Graph client of the delegated mailboxes (2.5.0; no app-only tokens). */
    public const MAILBOX_GRAPH_CLIENT_ID = 'nowo_mail_kit.mailbox.graph_api_client';

    /** Service id of the Graph normaliser of the delegated mailboxes (2.5.0; mailbox HTML sanitiser). */
    public const MAILBOX_NORMALIZER_ID = 'nowo_mail_kit.mailbox.graph_normalizer';

    /** Parameter: `mailboxes.graph.outbound_url_guard` when the Graph provider is on (2.5.0), read by the pass. */
    public const MAILBOX_URL_GUARD_PARAMETER = 'nowo_mail_kit.mailboxes.graph.outbound_url_guard';

    /** Container parameter: true when the configuration is read from Instance Settings (1.6). */
    public const INSTANCE_SETTINGS_PARAMETER = 'nowo_mail_kit.instance_settings';

    /** Build error of a log turned on without doctrine/orm (2.0, rule 8). */
    public const LOG_NEEDS_ORM = 'NowoMailKitBundle: "nowo_mail_kit.log.enabled: true" (the default) needs doctrine/orm, which MailKit no longer installs since 2.0: run "composer require doctrine/orm" or set "nowo_mail_kit.log.enabled: false" (see UPGRADING.md "To 2.0.0").';

    /**
     * Build-time parameter set by the prepend (2.0): whether the Doctrine ORM is installed and configured (the host's
     * `doctrine.orm` or the log mapping prepended by this extension). Removed from the compiled container.
     */
    public const ORM_PARAMETER = '.nowo_mail_kit.orm';

    /**
     * Unit-of-work boundaries of the {@see LegacyResendSettings} memo: event name => [listener method, priority].
     * Messenger and console events by name, so both components stay optional (REQ-RUNTIME-002).
     *
     * @var array<string, array{0: string, 1: int}>
     */
    public const LEGACY_RESEND_RESET_EVENTS = [
        'kernel.request'                                                   => ['onRequest', 4096],
        'kernel.terminate'                                                 => ['reset', -1024],
        'Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent' => ['reset', 4096],
        'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent'  => ['reset', -1024],
        'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent'   => ['reset', -1024],
        'console.command'                                                  => ['reset', 4096],
        'console.terminate'                                                => ['reset', -1024],
    ];

    /**
     * `nowo_mail_kit`.
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * Prepends the Doctrine mapping of the log entities (when `log.map_entities` resolves to true, 1.6; it follows
     * `log.enabled` since 2.0, and needs the ORM), the FormKit profile and the AuthKit mail-ready checker.
     */
    public function prepend(ContainerBuilder $container): void
    {
        $orm = $container->hasExtension('doctrine') && interface_exists(EntityManagerInterface::class);
        if ($orm && $this->mapEntitiesInRawConfig($container)) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'NowoMailKitBundle' => [
                            'type'      => 'attribute',
                            'dir'       => __DIR__ . '/../Entity',
                            'prefix'    => 'Nowo\MailKitBundle\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
        }

        $container->setParameter(self::ORM_PARAMETER, $orm && $this->ormConfigured($container));
        $this->prependFormKitProfile($container);
        $this->assertNoLegacyResendArea($container);

        if ($container->hasExtension('nowo_auth_kit') && interface_exists(OutboundMailReadyCheckerInterface::class)) {
            // A host value of nowo_auth_kit.outbound_mail_ready_checker (loaded after the prepend) wins.
            $container->prependExtensionConfig('nowo_auth_kit', ['outbound_mail_ready_checker' => self::AUTH_KIT_CHECKER_ID]);
        }
    }

    /**
     * `log.map_entities` of the unprocessed configuration, resolved as {@see Configuration} does: the last literal
     * value wins; unset, it follows `log.enabled` (2.0; default true). The prepend runs before the configuration is
     * processed; a non-literal value keeps the mapping and is rejected later ({@see self::assertLiteralBooleans()}).
     */
    private function mapEntitiesInRawConfig(ContainerBuilder $container): bool
    {
        $map     = null;
        $enabled = true;
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $config) {
            $log = is_array($config['log'] ?? null) ? $config['log'] : [];
            if (($log['map_entities'] ?? null) !== null) {
                $map = !is_bool($log['map_entities']) || $log['map_entities'];
            }
            if (array_key_exists('enabled', $log)) {
                $enabled = !is_bool($log['enabled']) || $log['enabled'];
            }
        }

        return $map ?? $enabled;
    }

    /**
     * Whether a `doctrine` configuration (the host's or the log mapping prepended above) turns the ORM on.
     */
    private function ormConfigured(ContainerBuilder $container): bool
    {
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            if (array_key_exists('orm', $config)) {
                return true;
            }
        }

        return false;
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

        /** @var array{mailer: array{decorate: bool, dsn_provider: string|null, sender_provider: string|null, fallback_counts_as_ready: bool}, dsn: array{allowed_schemes: list<string>, allowed_hosts: list<string>, allow_private_hosts: bool, allow_insecure_tls: bool, resolve_dns: bool, dns_timeout: float}, sender: array{default_from: string|null, default_from_name: string|null, require_public_domain: bool}, resend: array{enabled: bool, api_timeout: float}, bootstrap: array{mailer_dsn: string|null, resend_api_key: string|null}, test_email: array{rate_limit: int, rate_interval: string}, log: array{enabled: bool, map_entities: bool, store_bodies: bool, retention_days: int, purge_batch_size: int}, personal_data: array{enabled: bool, subject_resolver: string|null}, doctrine: array{table_prefix: string, entity_manager: string|null}, web_ui: array{enabled: bool, path_prefix: string, layout_template: string|null, templates: array<string, string>, page_size: int, export: array{enabled: bool, max_rows: int, access_roles: list<string>, include_bodies: bool, body_batch_bytes: int}}, security: array{access_roles: list<string>, access_checker: string|null, allow_unauthenticated: bool}, conflict_response: array{status: int, retry_after: int}, brand: array{mode: string, name: string|null, colors: array<string, string>, max_image_bytes: int, max_total_bytes: int, max_image_side: int}, graph: array{enabled: bool, mailbox_provider: string|null, default_profile: string, profiles: array<string, array{tenant_id: string, client_id: string, client_secret: string, mailbox: string}>, http_client: string, token_cache: string, timeout: float, draft_cleanup: array{bus: string|null, delay_ms: int}, upload_hosts: list<string>, limits: array{max_attachments: int, max_attachment_bytes: int, max_message_bytes: int}, inbound: array{enabled: bool, webhook: array{path: string}}}, tracking: array<string, mixed>, mailboxes: array<string, mixed>} $config */
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
        $hasOrm      = $hasDoctrine && $this->hasOrm($container);
        $this->registerLog($container, $config['log'], $config['doctrine'], $hasOrm);
        $this->registerPersonalData($container, $config['personal_data'], ($config['log']['enabled'] || $hasOrm) && $config['log']['map_entities']);
        $this->registerMailer($container, $config, $settings);
        $this->registerTestMail($container, $config, $settings);
        $this->registerWebUi($container, $config, $settings);
        $this->registerBrand($container, $config['brand']);
        $this->registerGraph($container, $config['graph'], $config['doctrine'], $hasOrm);
        $this->registerMailboxes($container, $config['mailboxes'], $config['doctrine'], $hasDoctrine, $hasOrm);
        $this->registerTracking($container, $config['tracking'], $config['doctrine'], $config['personal_data']['enabled'], $hasDoctrine, $hasOrm);
        $this->registerConflictResponse($container, $config['conflict_response']);
        // REQ-SEC-006: the bundle's own declaration of its identity / access-key columns. Autoconfigured so that
        // MigrationsKit tags it (nowo:migrations:check-key-collations) without the host registering anything; the
        // features that are off declare their columns inactive rather than omitting them.
        $container->setDefinition(MailKitExactKeyColumns::class, (new Definition(MailKitExactKeyColumns::class, [
            $config['doctrine']['table_prefix'],
            $config['tracking']['enabled'] === true,
            $config['graph']['inbound']['enabled'],
            $config['mailboxes']['enabled'],
        ]))->setAutoconfigured(true));

        $container->setParameter('nowo_mail_kit.graph.webhook_path', $config['graph']['inbound']['webhook']['path']);
        $container->setDefinition(GraphWebhookRouteLoader::class, (new Definition(GraphWebhookRouteLoader::class, [
            $config['graph']['inbound']['enabled'],
            $config['graph']['inbound']['webhook']['path'],
        ]))->addTag('routing.loader'));
        $container->setDefinition(MailboxWebhookRouteLoader::class, (new Definition(MailboxWebhookRouteLoader::class, [
            $config['mailboxes']['enabled'] && $config['mailboxes']['graph']['enabled'],
            $config['mailboxes']['graph']['webhook']['path'],
        ]))->addTag('routing.loader'));
    }

    /**
     * 2.0 (rule 8): the Doctrine ORM is installed and, when the prepend ran, configured ({@see self::ORM_PARAMETER}).
     */
    private function hasOrm(ContainerBuilder $container): bool
    {
        return interface_exists(EntityManagerInterface::class)
            && (!$container->hasParameter(self::ORM_PARAMETER) || $container->getParameter(self::ORM_PARAMETER) === true);
    }

    /**
     * 1.13 (REQ-RUNTIME-003 rule 6): the conflict answer of the bundle's own routes (409 or 503, Retry-After,
     * X-Conflict-Code).
     *
     * @param array{status: int, retry_after: int} $conflict
     */
    private function registerConflictResponse(ContainerBuilder $container, array $conflict): void
    {
        $container->setDefinition(MailKitConflictListener::class, (new Definition(MailKitConflictListener::class, [
            new Definition(ConflictResponsePolicy::class, [$conflict['status'], $conflict['retry_after']]),
        ]))->addTag('kernel.event_listener', ['event' => KernelEvents::EXCEPTION, 'priority' => -64]));
    }

    /**
     * 1.8 (spec 229): Microsoft Graph sending, only with `graph.enabled: true` (nothing is registered otherwise). The
     * token provider, API client and sender are stateless; the mailbox port is the configured profiles or a host service.
     *
     * @param array{enabled: bool, mailbox_provider: string|null, default_profile: string, profiles: array<string, array{tenant_id: string, client_id: string, client_secret: string, mailbox: string}>, http_client: string, token_cache: string, timeout: float, draft_cleanup: array{bus: string|null, delay_ms: int}, upload_hosts: list<string>, limits: array{max_attachments: int, max_attachment_bytes: int, max_message_bytes: int}, inbound: array{enabled: bool, webhook: array{path: string}}} $graph
     * @param array{table_prefix: string, entity_manager: string|null} $doctrine
     * @param bool $hasOrm the Doctrine ORM is installed and configured (schema listener of the inbound tables)
     */
    private function registerGraph(ContainerBuilder $container, array $graph, array $doctrine, bool $hasOrm): void
    {
        if (!$graph['enabled']) {
            return;
        }
        $logger = new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE);

        $provider = $graph['mailbox_provider'];
        if (is_string($provider) && $provider !== '') {
            $container->setAlias(GraphMailboxProviderInterface::class, $provider)->setPublic(true);
        } else {
            $container->setDefinition(ConfiguredGraphMailboxProvider::class, new Definition(ConfiguredGraphMailboxProvider::class, [$graph['profiles'], $graph['default_profile']]));
            $container->setAlias(GraphMailboxProviderInterface::class, ConfiguredGraphMailboxProvider::class)->setPublic(true);
        }

        $container->setDefinition(GraphTokenProvider::class, new Definition(GraphTokenProvider::class, [
            new Reference($graph['http_client']),
            new Reference($graph['token_cache']),
            $graph['timeout'],
            new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $logger,
        ]));
        $container->setDefinition(GraphApiClient::class, new Definition(GraphApiClient::class, [
            new Reference($graph['http_client']),
            new Reference(GraphTokenProvider::class),
            $graph['upload_hosts'],
            $graph['timeout'],
            $logger,
        ]));
        $container->setDefinition(GraphMailSender::class, (new Definition(GraphMailSender::class, [
            new Reference(GraphMailboxProviderInterface::class),
            new Reference(GraphApiClient::class),
            new Definition(GraphSendLimits::class, [$graph['limits']['max_attachments'], $graph['limits']['max_attachment_bytes'], $graph['limits']['max_message_bytes']]),
            $logger,
            is_string($graph['draft_cleanup']['bus']) && $graph['draft_cleanup']['bus'] !== '' ? new Reference($graph['draft_cleanup']['bus']) : null,
            $graph['draft_cleanup']['delay_ms'],
        ]))->setPublic(true));
        if (is_string($graph['draft_cleanup']['bus']) && $graph['draft_cleanup']['bus'] !== '') {
            self::requireInterface(MessageBusInterface::class, 'NowoMailKitBundle: nowo_mail_kit.graph.draft_cleanup.bus requires symfony/messenger.');
            $container->setDefinition(DeleteGraphDraftHandler::class, (new Definition(DeleteGraphDraftHandler::class, [new Reference(GraphMailSender::class)]))
                ->addTag('messenger.message_handler', ['handles' => DeleteGraphDraft::class]));
        }
        $container->setDefinition(GraphTransportFactory::class, (new Definition(GraphTransportFactory::class, [
            new Reference(GraphMailSender::class),
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $logger,
        ]))->addTag('mailer.transport_factory'));

        $this->registerGraphInbound($container, $graph['inbound'], $doctrine, $hasOrm);
    }

    /**
     * 1.9: the inbound pipeline, only with `graph.inbound.enabled` (webhook controller, subscriptions, delta sweep,
     * processor, Messenger handlers, commands, DBAL store and its schema listener).
     *
     * @param array<string, mixed> $inbound
     * @param array{table_prefix: string, entity_manager: string|null} $doctrine
     * @param bool $hasOrm the Doctrine ORM is installed and configured (else `nowo:mail-kit:graph:schema` creates the tables)
     */
    private function registerGraphInbound(ContainerBuilder $container, array $inbound, array $doctrine, bool $hasOrm): void
    {
        /** @var array{enabled: bool, handler: string, profiles: list<string>, profiles_provider: string|null, bus: string, connection: string|null, folder: string, webhook: array{path: string, notification_url: string|null, max_body_bytes: int, max_notifications: int, rate_limit: int, client_rate_limit: int, allowed_ips: list<string>}, subscriptions: array{lifetime_minutes: int, renew_before_minutes: int}, sweep: array{initial_window: string, page_size: int, max_pages: int}, allow_remote_images: bool, retention_days: int, stale_claim_seconds: int, limits: array{max_message_bytes: int, max_attachments: int, max_attachment_bytes: int, max_total_attachment_bytes: int, max_html_bytes: int, allowed_types: list<string>}} $inbound */
        if (!$inbound['enabled']) {
            return;
        }
        self::requireInterface(MessageBusInterface::class, 'NowoMailKitBundle: nowo_mail_kit.graph.inbound requires symfony/messenger (the webhook and the sweep only enqueue work).');
        self::requireInterface(HtmlSanitizer::class, 'NowoMailKitBundle: nowo_mail_kit.graph.inbound requires symfony/html-sanitizer (inbound HTML is sanitised).');
        self::requireInterface(finfo::class, 'NowoMailKitBundle: nowo_mail_kit.graph.inbound requires the PHP fileinfo extension (attachment types are detected from the bytes).');
        if (!$this->hasBundle($container, 'DoctrineBundle') && !$container->hasExtension('doctrine')) {
            throw new LogicException('NowoMailKitBundle: nowo_mail_kit.graph.inbound requires doctrine/doctrine-bundle (DBAL connection of the inbound tables).');
        }
        $logger = new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $clock  = new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $bus    = new Reference($inbound['bus']);
        $limits = $inbound['limits'];

        $container->setDefinition(GraphInboundStore::class, new Definition(GraphInboundStore::class, [new Reference('doctrine'), $inbound['connection'], $doctrine['table_prefix'], $clock, $logger]));
        if ($hasOrm) {
            $container->setDefinition(GraphInboundSchemaListener::class, (new Definition(GraphInboundSchemaListener::class, [new Reference(GraphInboundStore::class), new Reference('doctrine'), $doctrine['entity_manager']]))
                ->addTag('doctrine.event_listener', ['event' => 'postGenerateSchema']));
        }

        $profilesProvider = $inbound['profiles_provider'];
        if (is_string($profilesProvider) && $profilesProvider !== '') {
            $container->setAlias(GraphInboundProfilesProviderInterface::class, $profilesProvider);
        } else {
            $container->setDefinition(ConfiguredInboundProfiles::class, new Definition(ConfiguredInboundProfiles::class, [$inbound['profiles'], new Reference(GraphMailboxProviderInterface::class)]));
            $container->setAlias(GraphInboundProfilesProviderInterface::class, ConfiguredInboundProfiles::class);
        }
        $container->setAlias(GraphInboundHandlerInterface::class, $inbound['handler']);

        $container->setDefinition(GraphMessageNormalizer::class, new Definition(GraphMessageNormalizer::class, [
            new Definition(InboundHtmlSanitizer::class, [$inbound['allow_remote_images'], $limits['max_html_bytes']]),
        ]));
        $container->setDefinition(GraphInboundProcessor::class, (new Definition(GraphInboundProcessor::class, [
            new Reference(GraphMailboxProviderInterface::class),
            new Reference(GraphApiClient::class),
            new Reference(GraphInboundStore::class),
            new Reference(GraphInboundHandlerInterface::class),
            new Reference(GraphMessageNormalizer::class),
            new Definition(InboundLimits::class, [$limits['max_message_bytes'], $limits['max_attachments'], $limits['max_attachment_bytes'], $limits['max_total_attachment_bytes'], $limits['max_html_bytes'], $limits['allowed_types']]),
            $inbound['stale_claim_seconds'],
            $logger,
        ]))->setPublic(true));
        $container->setDefinition(GraphDeltaSweeper::class, (new Definition(GraphDeltaSweeper::class, [
            new Reference(GraphMailboxProviderInterface::class),
            new Reference(GraphApiClient::class),
            new Reference(GraphInboundStore::class),
            $bus,
            $inbound['sweep']['initial_window'],
            $inbound['sweep']['page_size'],
            $inbound['folder'],
            $clock,
            $logger,
        ]))->setPublic(true));
        $container->setDefinition(GraphSubscriptionManager::class, (new Definition(GraphSubscriptionManager::class, [
            new Reference(GraphMailboxProviderInterface::class),
            new Reference(GraphApiClient::class),
            new Reference(GraphInboundStore::class),
            $inbound['webhook']['notification_url'],
            $inbound['subscriptions']['lifetime_minutes'],
            $inbound['subscriptions']['renew_before_minutes'],
            $inbound['folder'],
            $clock,
            $logger,
        ]))->setPublic(true));
        $container->setDefinition(GraphInboundMessageHandler::class, (new Definition(GraphInboundMessageHandler::class, [
            new Reference(GraphInboundProcessor::class),
            new Reference(GraphDeltaSweeper::class),
            new Reference(GraphSubscriptionManager::class),
            new Reference(GraphInboundProfilesProviderInterface::class),
            new Reference(GraphInboundStore::class),
            $inbound['sweep']['max_pages'],
            $inbound['retention_days'],
            $clock,
            $logger,
        ]))
            ->addTag('messenger.message_handler', ['handles' => FetchGraphMessage::class, 'method' => 'fetch'])
            ->addTag('messenger.message_handler', ['handles' => SweepGraphMailbox::class, 'method' => 'sweep'])
            ->addTag('messenger.message_handler', ['handles' => RenewGraphSubscriptions::class, 'method' => 'renew']));

        $container->setDefinition(self::GRAPH_WEBHOOK_LIMITER_ID, new Definition(RateLimiterFactory::class, [
            ['id' => 'nowo_mail_kit_graph_webhook', 'policy' => 'sliding_window', 'limit' => $inbound['webhook']['rate_limit'], 'interval' => '1 minute'],
            new Definition(CacheStorage::class, [new Reference('cache.app')]),
        ]));
        $container->setDefinition(self::GRAPH_WEBHOOK_CLIENT_LIMITER_ID, new Definition(RateLimiterFactory::class, [
            ['id' => 'nowo_mail_kit_graph_webhook_client', 'policy' => 'sliding_window', 'limit' => $inbound['webhook']['client_rate_limit'], 'interval' => '1 minute'],
            new Definition(CacheStorage::class, [new Reference('cache.app')]),
        ]));
        $container->setDefinition(GraphWebhookController::class, (new Definition(GraphWebhookController::class, [
            new Reference(GraphInboundStore::class),
            $bus,
            new Reference(self::GRAPH_WEBHOOK_LIMITER_ID),
            new Reference(self::GRAPH_WEBHOOK_CLIENT_LIMITER_ID),
            new Reference(GraphInboundProfilesProviderInterface::class),
            $inbound['webhook']['max_body_bytes'],
            $inbound['webhook']['max_notifications'],
            $inbound['webhook']['allowed_ips'],
            $logger,
        ]))->setPublic(true)->addTag('controller.service_arguments'));

        $container->setDefinition(GraphSubscriptionsCommand::class, (new Definition(GraphSubscriptionsCommand::class, [new Reference(GraphSubscriptionManager::class), new Reference(GraphInboundProfilesProviderInterface::class)]))
            ->addTag('console.command', ['command' => GraphSubscriptionsCommand::NAME]));
        $container->setDefinition(GraphSweepCommand::class, (new Definition(GraphSweepCommand::class, [new Reference(GraphInboundMessageHandler::class)]))
            ->addTag('console.command', ['command' => GraphSweepCommand::NAME]));
        $container->setDefinition(GraphSchemaCommand::class, (new Definition(GraphSchemaCommand::class, [new Reference(GraphInboundStore::class)]))
            ->addTag('console.command', ['command' => GraphSchemaCommand::NAME]));
    }

    /**
     * 2.5.0 (design docs/design/DELEGATED_MAILBOXES.md): the delegated mailboxes, only with `mailboxes.enabled` (the
     * route loader is always registered and empty otherwise). Ports from the host; the engine, the attachment reader,
     * the Messenger handler and the commands are stateless; the Graph provider (client without app-only tokens,
     * normaliser with the mailbox HTML sanitiser, webhook controller and its buckets) only with `mailboxes.graph.enabled`.
     *
     * @param array<string, mixed> $mailboxes
     * @param array{table_prefix: string, entity_manager: string|null} $doctrine
     * @param bool $hasDoctrine DoctrineBundle registered
     * @param bool $hasOrm the Doctrine ORM is installed and configured (else `nowo:mail-kit:mailbox:schema` creates the tables)
     */
    private function registerMailboxes(ContainerBuilder $container, array $mailboxes, array $doctrine, bool $hasDoctrine, bool $hasOrm): void
    {
        /** @var array{enabled: bool, token_provider: string, directory: string, sink: string, exclusion_policy: string|null, call_observer: string|null, http_client: string, connection: string|null, bus: string, timeout: float, max_folders: int, sync: array{initial_window: string, max_initial_window: string, resync_window: string, page_size: int, max_pages_per_run: int, lease_seconds: int}, limits: array{max_message_bytes: int, max_html_bytes: int, max_attachment_bytes: int}, html: array{remote_images: string}, graph: array{enabled: bool, renew_before: string, outbound_url_guard: string, webhook: array{path: string, notification_url: string|null, max_body_bytes: int, max_notifications: int, allowed_ips: list<string>, rate_limit: array{limit: int, interval: string}, client_rate_limit: array{limit: int, interval: string}}}} $mailboxes */
        if (!$mailboxes['enabled']) {
            return;
        }
        self::requireInterface(MessageBusInterface::class, 'NowoMailKitBundle: nowo_mail_kit.mailboxes requires symfony/messenger (the webhook and the sweep only enqueue work).');
        self::requireInterface(HtmlSanitizer::class, 'NowoMailKitBundle: nowo_mail_kit.mailboxes requires symfony/html-sanitizer (received HTML is sanitised).');
        self::requireInterface(finfo::class, 'NowoMailKitBundle: nowo_mail_kit.mailboxes requires the PHP fileinfo extension (attachment types are detected from the bytes).');
        if (!$hasDoctrine) {
            throw new LogicException('NowoMailKitBundle: nowo_mail_kit.mailboxes requires doctrine/doctrine-bundle (DBAL connection of the mailbox tables).');
        }
        $logger = new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $clock  = new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $bus    = new Reference($mailboxes['bus']);
        $graph  = $mailboxes['graph'];

        $container->setDefinition(MailboxStore::class, new Definition(MailboxStore::class, [new Reference('doctrine'), $mailboxes['connection'], $doctrine['table_prefix'], $clock, $logger]));
        if ($hasOrm) {
            $container->setDefinition(MailboxSchemaListener::class, (new Definition(MailboxSchemaListener::class, [new Reference(MailboxStore::class), new Reference('doctrine'), $doctrine['entity_manager']]))
                ->addTag('doctrine.event_listener', ['event' => 'postGenerateSchema']));
        }

        $container->setAlias(MailboxTokenProviderInterface::class, $mailboxes['token_provider']);
        $container->setAlias(MailboxDirectoryInterface::class, $mailboxes['directory']);
        $container->setAlias(MailboxMessageSinkInterface::class, $mailboxes['sink']);
        $exclusion = $mailboxes['exclusion_policy'];
        if (is_string($exclusion) && $exclusion !== '') {
            $container->setAlias(MailboxExclusionPolicyInterface::class, $exclusion);
        } else {
            $container->setDefinition(KeepAllExclusionPolicy::class, new Definition(KeepAllExclusionPolicy::class));
            $container->setAlias(MailboxExclusionPolicyInterface::class, KeepAllExclusionPolicy::class);
        }
        $observer = $mailboxes['call_observer'];
        if (is_string($observer) && $observer !== '') {
            $container->setAlias(MailboxCallObserverInterface::class, $observer);
        } else {
            $container->setDefinition(NullMailboxCallObserver::class, new Definition(NullMailboxCallObserver::class));
            $container->setAlias(MailboxCallObserverInterface::class, NullMailboxCallObserver::class);
        }

        $gateways = [];
        if ($graph['enabled']) {
            $container->setParameter(self::MAILBOX_URL_GUARD_PARAMETER, $graph['outbound_url_guard']);
            $container->setDefinition(self::MAILBOX_GRAPH_CLIENT_ID, new Definition(GraphApiClient::class, [
                new Reference($mailboxes['http_client']),
                null,
                GraphApiClient::DEFAULT_UPLOAD_HOSTS,
                $mailboxes['timeout'],
                $logger,
                null,
            ]));
            $container->setDefinition(self::MAILBOX_NORMALIZER_ID, new Definition(GraphMessageNormalizer::class, [
                new Definition(MailHtmlSanitizer::class, [RemoteImages::from($mailboxes['html']['remote_images']), $mailboxes['limits']['max_html_bytes']]),
            ]));
            $container->setDefinition(GraphMailboxGateway::class, new Definition(GraphMailboxGateway::class, [
                new Reference(self::MAILBOX_GRAPH_CLIENT_ID),
                new Reference(self::MAILBOX_NORMALIZER_ID),
                $mailboxes['limits']['max_message_bytes'],
            ]));
            $gateways[MailboxProvider::Graph->value] = new Reference(GraphMailboxGateway::class);
        }

        $sync = $mailboxes['sync'];
        $container->setDefinition(MailboxSyncEngine::class, (new Definition(MailboxSyncEngine::class, [
            new Reference(MailboxStore::class),
            new Reference(MailboxDirectoryInterface::class),
            new Reference(MailboxTokenProviderInterface::class),
            new Reference(MailboxMessageSinkInterface::class),
            new Reference(MailboxExclusionPolicyInterface::class),
            new Reference(MailboxCallObserverInterface::class),
            $gateways,
            $bus,
            new Definition(MailboxSyncOptions::class, [
                $mailboxes['max_folders'],
                $sync['initial_window'],
                $sync['max_initial_window'],
                $sync['resync_window'],
                $sync['page_size'],
                $sync['max_pages_per_run'],
                $sync['lease_seconds'],
                $mailboxes['limits']['max_attachment_bytes'],
                $graph['webhook']['notification_url'],
                $graph['renew_before'],
                GraphSubscriptionManager::MAX_LIFETIME_MINUTES,
            ]),
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $clock,
            $logger,
        ]))->setPublic(true));
        $container->setAlias(MailboxSyncInterface::class, MailboxSyncEngine::class)->setPublic(true);
        $container->setDefinition(MailboxAttachmentReader::class, (new Definition(MailboxAttachmentReader::class, [
            new Reference(MailboxSyncEngine::class),
            $mailboxes['limits']['max_attachment_bytes'],
        ]))->setPublic(true));
        $container->setAlias(MailboxAttachmentReaderInterface::class, MailboxAttachmentReader::class)->setPublic(true);
        $container->setDefinition(MailboxMessageHandler::class, (new Definition(MailboxMessageHandler::class, [new Reference(MailboxSyncEngine::class), $bus, $logger]))
            ->addTag('messenger.message_handler', ['handles' => SyncMailboxFolder::class, 'method' => 'sync'])
            ->addTag('messenger.message_handler', ['handles' => FetchMailboxMessage::class, 'method' => 'fetch'])
            ->addTag('messenger.message_handler', ['handles' => RenewMailboxSubscriptions::class, 'method' => 'renew']));

        if ($graph['enabled']) {
            $webhook = $graph['webhook'];
            $container->setDefinition(self::MAILBOX_WEBHOOK_LIMITER_ID, new Definition(RateLimiterFactory::class, [
                ['id' => 'nowo_mail_kit_mailbox_webhook', 'policy' => 'sliding_window', 'limit' => $webhook['rate_limit']['limit'], 'interval' => $webhook['rate_limit']['interval']],
                new Definition(CacheStorage::class, [new Reference('cache.app')]),
            ]));
            $container->setDefinition(self::MAILBOX_WEBHOOK_CLIENT_LIMITER_ID, new Definition(RateLimiterFactory::class, [
                ['id' => 'nowo_mail_kit_mailbox_webhook_client', 'policy' => 'sliding_window', 'limit' => $webhook['client_rate_limit']['limit'], 'interval' => $webhook['client_rate_limit']['interval']],
                new Definition(CacheStorage::class, [new Reference('cache.app')]),
            ]));
            $container->setDefinition(MailboxGraphWebhookController::class, (new Definition(MailboxGraphWebhookController::class, [
                new Reference(MailboxStore::class),
                $bus,
                new Reference(self::MAILBOX_WEBHOOK_LIMITER_ID),
                new Reference(self::MAILBOX_WEBHOOK_CLIENT_LIMITER_ID),
                $webhook['max_body_bytes'],
                $webhook['max_notifications'],
                $webhook['allowed_ips'],
                $logger,
            ]))->setPublic(true)->addTag('controller.service_arguments'));
        }

        $container->setDefinition(MailboxSchemaCommand::class, (new Definition(MailboxSchemaCommand::class, [new Reference(MailboxStore::class)]))
            ->addTag('console.command', ['command' => MailboxSchemaCommand::NAME]));
        $container->setDefinition(MailboxSweepCommand::class, (new Definition(MailboxSweepCommand::class, [new Reference(MailboxSyncEngine::class)]))
            ->addTag('console.command', ['command' => MailboxSweepCommand::NAME]));
        $container->setDefinition(MailboxSubscriptionsCommand::class, (new Definition(MailboxSubscriptionsCommand::class, [new Reference(MailboxSyncEngine::class)]))
            ->addTag('console.command', ['command' => MailboxSubscriptionsCommand::NAME]));
        $container->setDefinition(MailboxStatusCommand::class, (new Definition(MailboxStatusCommand::class, [new Reference(MailboxSyncInterface::class)]))
            ->addTag('console.command', ['command' => MailboxStatusCommand::NAME]));
    }

    /**
     * 1.10 (Beacon spec 230 block B): open pixel and link rewriting, only with `tracking.enabled: true` (nothing is
     * registered otherwise). The store, signer, classifier, tracker and pixel controller are immutable; the host
     * provides the policy (and optionally the link rewriter, link filter and recipient resolver).
     *
     * @param array<string, mixed> $tracking
     * @param array{table_prefix: string, entity_manager: string|null} $doctrine
     * @param bool $personalData `personal_data.enabled`
     * @param bool $hasDoctrine DoctrineBundle registered
     * @param bool $hasOrm the Doctrine ORM is installed and configured (else `nowo:mail-kit:tracking:schema` creates the table)
     */
    private function registerTracking(ContainerBuilder $container, array $tracking, array $doctrine, bool $personalData, bool $hasDoctrine, bool $hasOrm): void
    {
        /** @var array{enabled: bool, policy: string, link_rewriter: string|null, link_filter: string|null, recipient_resolver: string|null, public_base_url: string, path_prefix: string, secret: string|null, previous_secrets: list<string>, retention: string, purge_batch_size: int, connection: string|null, bus: string|null, extra_never_track_templates: list<string>, max_opens_per_send: int, skip_redacted: bool, max_html_bytes: int, links: array{allowed_hosts: list<string>, allowed_path_prefixes: list<string>, credential_query_parameters: list<string>, credential_path_segments: list<string>, essential_patterns: list<string>, max_links: int}, machine_opens: array{user_agents: list<string>, ip_ranges: list<string>}} $tracking */
        if (!$tracking['enabled']) {
            return;
        }
        // Configuration lets "" through (ValidateEnvPlaceholdersPass re-processes the config with every env placeholder
        // replaced by ""); here the real value is known: a literal must be a valid URL, an env value is re-checked at runtime.
        if (!TrackingBaseUrl::isPlaceholder($tracking['public_base_url']) && !TrackingBaseUrl::isValid($tracking['public_base_url'])) {
            throw new InvalidConfigurationException('nowo_mail_kit.tracking.public_base_url must be an absolute http(s) URL without user info, query or fragment (or an env placeholder such as %env(DEFAULT_URI)%).');
        }
        if (!$hasDoctrine) {
            throw new LogicException('NowoMailKitBundle: nowo_mail_kit.tracking requires doctrine/doctrine-bundle (DBAL connection of the tracking table).');
        }
        $bus = is_string($tracking['bus']) && $tracking['bus'] !== '' ? $tracking['bus'] : null;
        if ($bus !== null) {
            self::requireInterface(MessageBusInterface::class, 'NowoMailKitBundle: nowo_mail_kit.tracking.bus requires symfony/messenger.');
        }
        $logger    = new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $clock     = new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $retention = Configuration::daysSeconds($tracking['retention']);

        $container->setDefinition(TrackingStore::class, new Definition(TrackingStore::class, [new Reference('doctrine'), $tracking['connection'], $doctrine['table_prefix'], $clock, $logger, $tracking['max_opens_per_send']]));
        $container->setAlias(EmailTrackingReaderInterface::class, TrackingStore::class)->setPublic(true);
        $container->setAlias(EmailTrackingPersonalDataInterface::class, TrackingStore::class)->setPublic(true);
        if ($hasOrm) {
            $container->setDefinition(TrackingSchemaListener::class, (new Definition(TrackingSchemaListener::class, [new Reference(TrackingStore::class), new Reference('doctrine'), $doctrine['entity_manager']]))
                ->addTag('doctrine.event_listener', ['event' => 'postGenerateSchema']));
        }

        // Literal secrets are checked now (audit NU-M1); %env()% values when the signer is first built — lazily, so a
        // bad secret leaves mail untracked (logged as critical) instead of breaking every send.
        // With `secret: null` the previous secrets are previous kernel secrets (derived like the current one, any length).
        foreach (is_string($tracking['secret']) && $tracking['secret'] !== '' ? [$tracking['secret'], ...$tracking['previous_secrets']] : [] as $secret) {
            if (is_string($secret) && !str_contains($secret, '%env(') && $container->resolveEnvPlaceholders($secret, true) === $secret && strlen($secret) < 32) {
                throw new InvalidConfigurationException('nowo_mail_kit.tracking.secret and tracking.previous_secrets must be at least 32 bytes long (use a random value from %env()%).');
            }
        }
        $signer = is_string($tracking['secret']) && $tracking['secret'] !== ''
            ? new Definition(TrackingTokenSigner::class, [$tracking['secret'], $tracking['previous_secrets'], $retention, $clock])
            : (new Definition(TrackingTokenSigner::class, ['%kernel.secret%', $tracking['previous_secrets'], $retention, $clock]))->setFactory([TrackingTokenSigner::class, 'fromKernelSecret']);
        $container->setDefinition(TrackingTokenSigner::class, $signer);

        $container->setAlias(EmailTrackingPolicyInterface::class, $tracking['policy']);
        $rewriter = $tracking['link_rewriter'];
        if (is_string($rewriter) && $rewriter !== '') {
            $container->setAlias(EmailLinkRewriterInterface::class, $rewriter);
        } else {
            $container->setDefinition(NullEmailLinkRewriter::class, new Definition(NullEmailLinkRewriter::class));
            $container->setAlias(EmailLinkRewriterInterface::class, NullEmailLinkRewriter::class);
        }
        $filter = $tracking['link_filter'];
        if (is_string($filter) && $filter !== '') {
            $container->setAlias(TrackableLinkFilterInterface::class, $filter);
        } else {
            $container->setDefinition(ConfiguredTrackableLinkFilter::class, new Definition(ConfiguredTrackableLinkFilter::class, [$tracking['links']['allowed_hosts'], $tracking['links']['allowed_path_prefixes']]));
            $container->setAlias(TrackableLinkFilterInterface::class, ConfiguredTrackableLinkFilter::class);
        }

        $container->setDefinition(EmailTracker::class, (new Definition(EmailTracker::class, [
            new Reference(EmailTrackingPolicyInterface::class),
            new Reference(EmailLinkRewriterInterface::class),
            new Reference(TrackableLinkFilterInterface::class),
            new Definition(CredentialLinkDetector::class, [$tracking['links']['credential_query_parameters'], $tracking['links']['credential_path_segments']]),
            new ServiceClosureArgument(new Reference(TrackingTokenSigner::class)),
            new Reference(TrackingStore::class),
            new Definition(HtmlLinkTracker::class, [$tracking['links']['max_links']]),
            $tracking['public_base_url'],
            $tracking['extra_never_track_templates'],
            $tracking['skip_redacted'],
            $tracking['links']['essential_patterns'],
            $tracking['max_html_bytes'],
            new Reference('twig.mime_body_renderer', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $logger,
            $tracking['path_prefix'],
        ]))->setPublic(true));

        $container->setDefinition(OpenClassifier::class, new Definition(OpenClassifier::class, [$tracking['machine_opens']['user_agents'], $tracking['machine_opens']['ip_ranges']]));
        $container->setDefinition(OpenRecorder::class, new Definition(OpenRecorder::class, [
            new Reference(TrackingStore::class),
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $logger,
        ]));
        if ($bus !== null) {
            $container->setDefinition(RecordMailOpenHandler::class, (new Definition(RecordMailOpenHandler::class, [new Reference(OpenRecorder::class)]))
                ->addTag('messenger.message_handler', ['handles' => RecordMailOpen::class]));
        }
        $container->setDefinition(TrackingPixelController::class, (new Definition(TrackingPixelController::class, [
            new ServiceClosureArgument(new Reference(TrackingTokenSigner::class)),
            new Reference(OpenClassifier::class),
            new Reference(OpenRecorder::class),
            $bus !== null ? new Reference($bus) : null,
            $clock,
            $logger,
        ]))->setPublic(true)->addTag('controller.service_arguments'));
        $container->setDefinition(TrackingRouteLoader::class, (new Definition(TrackingRouteLoader::class, [$tracking['path_prefix']]))->addTag('routing.loader'));

        $container->setDefinition(TrackingPurgeCommand::class, (new Definition(TrackingPurgeCommand::class, [
            new Reference(TrackingStore::class),
            $retention,
            $tracking['purge_batch_size'],
            new Reference('clock'),
            $logger,
        ]))->addTag('console.command', ['command' => TrackingPurgeCommand::NAME]));
        $container->setDefinition(TrackingSchemaCommand::class, (new Definition(TrackingSchemaCommand::class, [new Reference(TrackingStore::class)]))
            ->addTag('console.command', ['command' => TrackingSchemaCommand::NAME]));

        if (!$personalData || !interface_exists(PersonalDataEraserInterface::class)) {
            return;
        }
        $resolver = $tracking['recipient_resolver'];
        if (is_string($resolver) && $resolver !== '') {
            $container->setAlias(TrackingRecipientResolverInterface::class, $resolver);
        } else {
            $container->setDefinition(DefaultTrackingRecipientResolver::class, new Definition(DefaultTrackingRecipientResolver::class));
            $container->setAlias(TrackingRecipientResolverInterface::class, DefaultTrackingRecipientResolver::class);
        }
        $container->setDefinition(EmailTrackingPersonalDataEraser::class, (new Definition(EmailTrackingPersonalDataEraser::class, [
            new Reference(EmailTrackingPersonalDataInterface::class),
            new Reference(TrackingRecipientResolverInterface::class),
        ]))->addTag('nowo_user_kit.personal_data.eraser', ['priority' => EmailTrackingPersonalDataEraser::PRIORITY]));
        $container->setDefinition(EmailTrackingPersonalDataExportProvider::class, (new Definition(EmailTrackingPersonalDataExportProvider::class, [
            new Reference(EmailTrackingPersonalDataInterface::class),
            new Reference(TrackingRecipientResolverInterface::class),
        ]))->addTag('nowo_user_kit.personal_data.export_provider', ['priority' => EmailTrackingPersonalDataExportProvider::PRIORITY]));
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
            throw new LogicException(sprintf('NowoMailKitBundle reads its configuration from nowo-tech/instance-settings-bundle by default, and since 2.0 that package is no longer installed with it: run "composer require nowo-tech/instance-settings-bundle" and register Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle in config/bundles.php, or set %s to your own service ids (configuration from host ports, no Instance Settings). See UPGRADING.md "To 2.0.0".', implode(' and ', $missing)));
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
            // ... and at every request / message / command boundary (REQ-RUNTIME-002), event names as strings so
            // Messenger and Console stay optional; only an already-built memo is reset.
            $resetListener = new Definition(LegacyResendSettingsResetListener::class, [
                new IteratorArgument([new Reference(LegacyResendSettings::class, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE)]),
            ]);
            foreach (self::LEGACY_RESEND_RESET_EVENTS as $event => [$method, $priority]) {
                $resetListener->addTag('kernel.event_listener', ['event' => $event, 'method' => $method, 'priority' => $priority]);
            }
            $container->setDefinition(LegacyResendSettingsResetListener::class, $resetListener);
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
     * The recorder (erasure, retention and the forget / purge commands) is registered whenever the Doctrine ORM is:
     * with `log.enabled: false` it records nothing but still erases and purges the rows written while the log was on
     * (1.6, LL-MK-M1). Only without the ORM is the erasure port a no-op.
     *
     * 2.0 (rule 8): `doctrine/orm` is suggested, not required. A log turned on without it fails the build.
     *
     * @param array{enabled: bool, map_entities: bool, store_bodies: bool, retention_days: int, purge_batch_size: int} $log
     * @param array{table_prefix: string, entity_manager: string|null} $doctrine
     * @param bool $hasOrm DoctrineBundle registered and the ORM installed and configured
     */
    private function registerLog(ContainerBuilder $container, array $log, array $doctrine, bool $hasOrm): void
    {
        if ($log['enabled']) {
            self::requireInterface(EntityManagerInterface::class, self::LOG_NEEDS_ORM);
        }
        if ($hasOrm) {
            $container->setDefinition(TablePrefixListener::class, (new Definition(TablePrefixListener::class, [$doctrine['table_prefix']]))
                ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));
        }

        if (!$log['enabled'] && !$hasOrm) {
            $container->setDefinition(NullSentMailErasure::class, new Definition(NullSentMailErasure::class));
            $container->setAlias(SentMailErasureInterface::class, NullSentMailErasure::class)->setPublic(true);
            $container->setAlias(SentMailPersonalDataEraserInterface::class, NullSentMailErasure::class)->setPublic(true);
            $container->setDefinition(NullSentMailPersonalDataExporter::class, new Definition(NullSentMailPersonalDataExporter::class));
            $container->setAlias(SentMailPersonalDataExporterInterface::class, NullSentMailPersonalDataExporter::class)->setPublic(true);

            return;
        }

        $container->setAlias(SentMailErasureInterface::class, SentMailRecorder::class)->setPublic(true);
        $container->setAlias(SentMailPersonalDataEraserInterface::class, SentMailRecorder::class)->setPublic(true);
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
            new Reference(SentMailPersonalDataEraserInterface::class),
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
        /** @var array{mailer: array{decorate: bool, dsn_provider: string|null, sender_provider: string|null, fallback_counts_as_ready: bool}, log: array{enabled: bool}, tracking: array{enabled: bool}} $config */
        $mailer   = $config['mailer'];
        $recorder = $config['log']['enabled'] ? new Reference(SentMailRecorder::class) : null;

        if ($settings) {
            $container->setDefinition(SettingsMailerDsnProvider::class, new Definition(SettingsMailerDsnProvider::class, [new Reference(MailSettings::class)]));
        }
        $provider = $mailer['dsn_provider'];
        $container->setAlias(MailerDsnProviderInterface::class, is_string($provider) && $provider !== '' ? $provider : SettingsMailerDsnProvider::class);

        $senderProvider = $mailer['sender_provider'];
        $container->setAlias(SenderIdentityProviderInterface::class, is_string($senderProvider) && $senderProvider !== '' ? $senderProvider : MailSettings::class);
        $container->setDefinition(SenderPolicy::class, new Definition(SenderPolicy::class, [
            new Reference(SenderIdentityProviderInterface::class),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));

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
            $config['tracking']['enabled'] ? new Reference(EmailTracker::class) : null,
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
            !$settings || $hostSender ? new Reference(SenderIdentityProviderInterface::class) : null,
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
            'mailer.decorate'                   => $config['mailer']['decorate'] ?? null,
            'dsn.allow_private_hosts'           => $config['dsn']['allow_private_hosts'] ?? null,
            'dsn.allow_insecure_tls'            => $config['dsn']['allow_insecure_tls'] ?? null,
            'resend.enabled'                    => $config['resend']['enabled'] ?? null,
            'log.enabled'                       => $config['log']['enabled'] ?? null,
            'log.map_entities'                  => $config['log']['map_entities'] ?? null,
            'log.store_bodies'                  => $config['log']['store_bodies'] ?? null,
            'personal_data.enabled'             => $config['personal_data']['enabled'] ?? null,
            'graph.enabled'                     => $config['graph']['enabled'] ?? null,
            'graph.inbound.enabled'             => $config['graph']['inbound']['enabled'] ?? null,
            'graph.inbound.allow_remote_images' => $config['graph']['inbound']['allow_remote_images'] ?? null,
            'tracking.enabled'                  => $config['tracking']['enabled'] ?? null,
            'tracking.skip_redacted'            => $config['tracking']['skip_redacted'] ?? null,
            'web_ui.enabled'                    => $config['web_ui']['enabled'] ?? null,
            'web_ui.export.enabled'             => $config['web_ui']['export']['enabled'] ?? null,
            'web_ui.export.include_bodies'      => $config['web_ui']['export']['include_bodies'] ?? null,
            'security.allow_unauthenticated'    => $config['security']['allow_unauthenticated'] ?? null,
        ];
        foreach ($switches as $path => $value) {
            if (!is_bool($value)) {
                throw new InvalidConfigurationException(sprintf('nowo_mail_kit.%s must be a literal boolean (true or false); environment placeholders such as %%env(bool:...)%% are not supported for these switches.', $path));
            }
        }
    }

    /**
     * Fails the build when an optional component's interface or class is missing.
     */
    public static function requireInterface(string $interface, string $message): void
    {
        if (!interface_exists($interface) && !class_exists($interface)) {
            throw new LogicException($message);
        }
    }

    private function hasBundle(ContainerBuilder $container, string $name): bool
    {
        $bundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : [];

        return is_array($bundles) && array_key_exists($name, $bundles);
    }
}
