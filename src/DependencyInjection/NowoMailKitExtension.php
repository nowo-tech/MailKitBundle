<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\DependencyInjection;

use LogicException;
use Nowo\AuthKitBundle\Mailer\OutboundMailReadyCheckerInterface;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Security\AccessGuard as InstanceSettingsAccessGuard;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\MailKitBundle\Command\ForgetRecipientCommand;
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
use Nowo\MailKitBundle\Form\SentMailFilterType;
use Nowo\MailKitBundle\Integration\AuthKit\MailKitOutboundMailReadyChecker;
use Nowo\MailKitBundle\Log\SentMailCsvExporter;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Log\SentMailRepository;
use Nowo\MailKitBundle\Mailer\MailKitMailer;
use Nowo\MailKitBundle\Mailer\RuntimeTransport;
use Nowo\MailKitBundle\NowoMailKitBundle;
use Nowo\MailKitBundle\Readiness\OutboundMailReadiness;
use Nowo\MailKitBundle\Resend\ResendApiChecker;
use Nowo\MailKitBundle\Routing\MailKitRouteLoader;
use Nowo\MailKitBundle\Security\AccessGuard;
use Nowo\MailKitBundle\Security\AllowAllMailKitAccessChecker;
use Nowo\MailKitBundle\Security\ConfigurableMailKitAccessChecker;
use Nowo\MailKitBundle\Security\MailKitAccessCheckerInterface;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Nowo\MailKitBundle\Settings\MailerSettingsSection;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Settings\ResendSettingsSection;
use Nowo\MailKitBundle\TestMail\TestMailGuard;
use Nowo\MailKitBundle\TestMail\TestMailSender;
use Nowo\MailKitBundle\Twig\MailKitTwigExtension;
use Nowo\MailKitBundle\Ui\AdminPages;
use Nowo\MailKitBundle\Ui\MailKitPages;
use Nowo\MailKitBundle\Ui\SettingsActions;
use Nowo\MailKitBundle\Validator\DeliverableSenderValidator;
use Nowo\MailKitBundle\Validator\MailerDsnConstraintValidator;
use Nowo\OutboundUrlGuardBundle\Dns\HostnameDnsLookup;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
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
use function interface_exists;
use function is_array;
use function is_bool;
use function is_string;
use function sprintf;

/**
 * Loads `nowo_mail_kit`: the Instance Settings sections, DSN / sender validation, the runtime transport and mailer
 * decorators, readiness (Twig, AuthKit adapter), test e-mail, the sent-mail log and (when enabled) the admin UI.
 */
final class NowoMailKitExtension extends Extension implements PrependExtensionInterface
{
    /** Service id of the AuthKit adapter. */
    public const AUTH_KIT_CHECKER_ID = 'nowo_mail_kit.outbound_mail_ready_checker';

    /** Service id of the test e-mail rate limiter factory. */
    public const LIMITER_ID = 'nowo_mail_kit.test_email.limiter';

    /**
     * `nowo_mail_kit`.
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * Prepends the Doctrine mapping of the log entities, the FormKit profile and the AuthKit mail-ready checker.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('doctrine')) {
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

        if ($container->hasExtension('nowo_auth_kit') && interface_exists(OutboundMailReadyCheckerInterface::class)) {
            // A host value of nowo_auth_kit.outbound_mail_ready_checker (loaded after the prepend) wins.
            $container->prependExtensionConfig('nowo_auth_kit', ['outbound_mail_ready_checker' => self::AUTH_KIT_CHECKER_ID]);
        }
    }

    /**
     * Registers the services from the processed configuration.
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $this->assertLiteralBooleans($config);

        /** @var array{mailer: array{decorate: bool, dsn_provider: string|null, fallback_counts_as_ready: bool}, dsn: array{allowed_schemes: list<string>, allowed_hosts: list<string>, allow_private_hosts: bool, allow_insecure_tls: bool, resolve_dns: bool, dns_timeout: float}, sender: array{default_from: string|null, default_from_name: string|null, require_public_domain: bool}, resend: array{enabled: bool, api_timeout: float}, test_email: array{rate_limit: int, rate_interval: string}, log: array{enabled: bool, store_bodies: bool, retention_days: int, purge_batch_size: int}, doctrine: array{table_prefix: string, entity_manager: string|null}, web_ui: array{enabled: bool, path_prefix: string, layout_template: string|null, templates: array<string, string>, page_size: int, export: array{enabled: bool, max_rows: int, access_roles: list<string>, include_bodies: bool}}, security: array{access_roles: list<string>, access_checker: string|null, allow_unauthenticated: bool}} $config */
        $webUi = $config['web_ui'];

        if (!$this->hasBundle($container, 'NowoInstanceSettingsBundle') && !$container->hasExtension('nowo_instance_settings')) {
            throw new LogicException('NowoMailKitBundle requires nowo-tech/instance-settings-bundle: register Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle in config/bundles.php.');
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

        $this->registerSettings($container, $config);
        $this->registerValidation($container, $config['dsn']);
        $this->registerLog($container, $config['log'], $config['doctrine']);
        $this->registerMailer($container, $config);
        $this->registerTestMail($container, $config);
        $this->registerWebUi($container, $config);
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
        /** @var array{resend: array{enabled: bool}, sender: array{default_from: string|null, default_from_name: string|null, require_public_domain: bool}, web_ui: array{enabled: bool}} $config */
        $actions = $config['web_ui']['enabled'] ? new Reference(SettingsActions::class) : null;

        $container->setDefinition(MailSettings::class, new Definition(MailSettings::class, [
            new Reference(InstanceSettingsInterface::class),
            $config['resend']['enabled'],
        ]));

        $container->setDefinition(MailerSettingsSection::class, (new Definition(MailerSettingsSection::class, [
            $config['sender']['default_from'],
            $config['sender']['default_from_name'],
            $config['sender']['require_public_domain'],
            $actions,
        ]))->addTag(NowoInstanceSettingsBundle::SECTION_TAG, ['priority' => 50]));

        if ($config['resend']['enabled']) {
            $container->setDefinition(ResendSettingsSection::class, (new Definition(ResendSettingsSection::class, [
                $config['sender']['require_public_domain'],
                $actions,
            ]))->addTag(NowoInstanceSettingsBundle::SECTION_TAG, ['priority' => 49]));
        }
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
     * @param array{enabled: bool, store_bodies: bool, retention_days: int, purge_batch_size: int} $log
     * @param array{table_prefix: string, entity_manager: string|null} $doctrine
     */
    private function registerLog(ContainerBuilder $container, array $log, array $doctrine): void
    {
        $container->setDefinition(TablePrefixListener::class, (new Definition(TablePrefixListener::class, [$doctrine['table_prefix']]))
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));

        if (!$log['enabled']) {
            return;
        }

        $container->setDefinition(SentMailRecorder::class, (new Definition(SentMailRecorder::class, [
            new Reference('doctrine'),
            $doctrine['entity_manager'],
            $doctrine['table_prefix'],
            $log['store_bodies'],
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true));
        $container->setDefinition(SentMailRepository::class, (new Definition(SentMailRepository::class, [
            new Reference('doctrine'),
            $doctrine['entity_manager'],
        ]))->setPublic(true));

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
     * @param array<string, mixed> $config
     */
    private function registerMailer(ContainerBuilder $container, array $config): void
    {
        /** @var array{mailer: array{decorate: bool, dsn_provider: string|null, fallback_counts_as_ready: bool}, log: array{enabled: bool}} $config */
        $mailer   = $config['mailer'];
        $recorder = $config['log']['enabled'] ? new Reference(SentMailRecorder::class) : null;

        $container->setDefinition(SettingsMailerDsnProvider::class, new Definition(SettingsMailerDsnProvider::class, [new Reference(MailSettings::class)]));
        $provider = $mailer['dsn_provider'];
        $container->setAlias(MailerDsnProviderInterface::class, is_string($provider) && $provider !== '' ? $provider : SettingsMailerDsnProvider::class);

        $container->setDefinition(SenderPolicy::class, new Definition(SenderPolicy::class, [new Reference(MailSettings::class)]));

        $transport = (new Definition(RuntimeTransport::class, [
            new Reference($mailer['decorate'] ? RuntimeTransport::class . '.inner' : 'mailer.transports'),
            new Reference(MailerDsnProviderInterface::class),
            new Reference(MailerDsnValidator::class),
            new Reference('mailer.transport_factory'),
            new Reference(SenderPolicy::class),
            $recorder,
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
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
        ]))->setPublic(true));
        $container->setDefinition(MailKitTwigExtension::class, (new Definition(MailKitTwigExtension::class, [new Reference(OutboundMailReadiness::class)]))
            ->addTag('twig.extension'));
        $container->setDefinition(SettingsChangedListener::class, (new Definition(SettingsChangedListener::class, [
            new Reference(RuntimeTransport::class),
            new Reference(OutboundMailReadiness::class),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->addTag('kernel.event_listener', ['event' => SettingsChangedEvent::class]));

        if (interface_exists(OutboundMailReadyCheckerInterface::class)) {
            $container->setDefinition(self::AUTH_KIT_CHECKER_ID, (new Definition(MailKitOutboundMailReadyChecker::class, [new Reference(OutboundMailReadiness::class)]))
                ->setPublic(true));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerTestMail(ContainerBuilder $container, array $config): void
    {
        /* @var array{test_email: array{rate_limit: int, rate_interval: string}, sender: array{require_public_domain: bool}, resend: array{enabled: bool, api_timeout: float}} $config */
        $container->setDefinition(TestMailSender::class, (new Definition(TestMailSender::class, [
            new Reference(RuntimeTransport::class),
            new Reference(MailSettings::class),
            new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $config['sender']['require_public_domain'],
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
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
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerWebUi(ContainerBuilder $container, array $config): void
    {
        /** @var array{web_ui: array{enabled: bool, path_prefix: string, layout_template: string|null, templates: array<string, string>, page_size: int, export: array{enabled: bool, max_rows: int, access_roles: list<string>, include_bodies: bool}}, security: array{access_roles: list<string>, access_checker: string|null, allow_unauthenticated: bool}, log: array{enabled: bool, store_bodies: bool}, resend: array{enabled: bool}} $config */
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
            '$settings'      => new Reference(MailSettings::class),
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
            'log.store_bodies'               => $config['log']['store_bodies'] ?? null,
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
