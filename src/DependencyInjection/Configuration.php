<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\DependencyInjection;

use Nowo\MailKitBundle\Brand\BrandedEmailHelper;
use Nowo\MailKitBundle\Brand\BrandedEmailText;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\Log\SentMailCsvExporter;
use Nowo\MailKitBundle\Ui\MailKitPages;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function array_diff;
use function array_intersect;
use function array_keys;
use function array_values;
use function filter_var;
use function implode;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strtolower;
use function strtotime;
use function trim;

use const FILTER_VALIDATE_EMAIL;

/**
 * Configuration tree of `nowo_mail_kit` (REQ-SF-006: strict nodes, safe defaults).
 */
final class Configuration implements ConfigurationInterface
{
    /** Extension alias / root key. */
    public const ALIAS = 'nowo_mail_kit';

    /** Default URL prefix of the admin UI (sent-mail log and the settings actions). */
    public const DEFAULT_PATH_PREFIX = '/admin/mail';

    /** brand.mode: read a nowo-tech/brand-contracts provider when one exists (spec 220 S7). */
    public const BRAND_AUTO = 'auto';

    /** brand.mode: ignore any brand provider. */
    public const BRAND_OFF = 'off';

    /** DSN schemes that run host processes or drop every message: never accepted from the settings. */
    public const FORBIDDEN_SCHEMES = ['sendmail', 'sendmail+smtp', 'native', 'null'];

    /**
     * Builds the tree.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        /** @var ArrayNodeDefinition $root */
        $root = $treeBuilder->getRootNode();

        $root
            ->info('Nowo Mail Kit — runtime mailer DSN from Instance Settings, validation, test e-mail, sent-mail log.')
            ->children()
                ->arrayNode('mailer')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('decorate')
                            ->info('Decorate the framework mailer (mailer.mailer) and transports (mailer.transports) so every send resolves the DSN at runtime. false = only the MailKit services are available.')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('dsn_provider')
                            ->info('Service id implementing MailerDsnProviderInterface; null = the Instance Settings provider (mailer section).')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('sender_provider')
                            ->info('Service id implementing Sender\\SenderProviderInterface (default From / Reply-To); null = the Instance Settings mailer section. Since 1.5.')
                            ->defaultNull()
                        ->end()
                        ->booleanNode('fallback_counts_as_ready')
                            ->info('Treat the framework transport (MAILER_DSN) as deliverable for isOutboundMailReady() when no runtime DSN is stored.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('dsn')
                    ->info('Validation of the DSNs stored from the admin UI (the framework MAILER_DSN fallback is trusted ops configuration).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('allowed_schemes')
                            ->info('DSN schemes accepted from the settings. sendmail, native and null are always refused.')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue(MailerDsnValidator::DEFAULT_SCHEMES)
                            ->requiresAtLeastOneElement()
                            ->validate()
                                ->ifTrue(static fn (array $v): bool => array_intersect(self::lower($v), self::FORBIDDEN_SCHEMES) !== [])
                                ->then(static function (array $v): never {
                                    throw new InvalidConfigurationException(sprintf('nowo_mail_kit.dsn.allowed_schemes must not contain "%s": sendmail / native run host processes and null drops every message.', implode('", "', array_intersect(self::lower($v), self::FORBIDDEN_SCHEMES))));
                                })
                            ->end()
                        ->end()
                        ->arrayNode('allowed_hosts')
                            ->info('Hosts accepted even when private or local (for example an internal relay or "mailpit" in a demo). Exact, case-insensitive.')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue([])
                        ->end()
                        ->booleanNode('allow_private_hosts')
                            ->info('Accept DSN hosts in private / loopback / link-local ranges (SSRF guard off). Cloud metadata hosts stay refused.')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('allow_insecure_tls')
                            ->info('DEV only: accept verify_peer=0 / verify_peer_name=0 in stored DSNs (TLS certificate checks off).')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('resolve_dns')
                            ->info('Resolve DSN host names and refuse those pointing at private addresses (bounded lookup, see dns_timeout).')
                            ->defaultTrue()
                        ->end()
                        ->floatNode('dns_timeout')
                            ->info('Deadline of one DNS lookup, in seconds (REQ-RUNTIME-001).')
                            ->defaultValue(2.0)
                            ->min(0.1)
                            ->max(10.0)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('sender')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('default_from')
                            ->info('Default of the mailer.from setting (null = no default: messages must set From themselves).')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || filter_var($v, FILTER_VALIDATE_EMAIL) === false))
                                ->thenInvalid('nowo_mail_kit.sender.default_from must be an e-mail address or null, got %s.')
                            ->end()
                        ->end()
                        ->scalarNode('default_from_name')
                            ->info('Default of the mailer.from_name setting.')
                            ->defaultNull()
                        ->end()
                        ->booleanNode('require_public_domain')
                            ->info('Refuse sender addresses on non-public domains (no dot, .local, .localhost) in the settings.')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('resend')
                    ->info('Optional Resend provider of the mailer settings section (provider choice, Resend API key and sender, key check); needs symfony/resend-mailer to send.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->floatNode('api_timeout')
                            ->info('Deadline of the Resend key check (GET /domains), in seconds (REQ-RUNTIME-001).')
                            ->defaultValue(10.0)
                            ->min(1.0)
                            ->max(60.0)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('bootstrap')
                    ->info('One-time bootstrap values (since 1.2), read only by nowo:instance-settings:seed-from-fallbacks: map them to %env()% while migrating to DB-only mode, then remove them. Never used to send.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('mailer_dsn')
                            ->info('Copied into the mailer.dsn secret when none is stored (e.g. "%env(default::MAILER_DSN)%"); skipped when it fails the DSN validation.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('resend_api_key')
                            ->info('Copied into the mailer.resend_api_key secret when none is stored (needs resend.enabled: true; since 1.3, before: resend.api_key).')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('test_email')
                    ->info('"Send a test e-mail" actions of the settings sections and the CLI.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('rate_limit')
                            ->info('Test e-mails one user may send per rate_interval (sliding window over cache.app).')
                            ->defaultValue(5)
                            ->min(1)
                            ->max(100)
                        ->end()
                        ->scalarNode('rate_interval')
                            ->info('Window of the rate limit, a relative time such as "10 minutes".')
                            ->defaultValue('10 minutes')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || trim($v) === '' || strtotime('+' . $v, 0) === false || strtotime('+' . $v, 0) <= 0)
                                ->thenInvalid('nowo_mail_kit.test_email.rate_interval must be a relative time such as "10 minutes", got %s.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('log')
                    ->info('Sent-mail log (one row per message: status, subject, sender, recipients, transport).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->booleanNode('map_entities')
                            ->info('1.6: add the Doctrine mapping of the log entities (SentMail, SentMailBody). false (only with enabled: false) keeps them out of your entity manager, e.g. on an XML-mapping-only DDD host; drop or purge existing log tables yourself first. Default true; planned to follow log.enabled in 2.0.')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('store_bodies')
                            ->info('Also store the HTML / text bodies (personal data; secrets passed through the redact header are masked). Off by default (privacy / GDPR).')
                            ->defaultFalse()
                        ->end()
                        ->integerNode('retention_days')
                            ->info('Rows older than this are deleted by nowo:mail-kit:log:purge (schedule it).')
                            ->defaultValue(30)
                            ->min(1)
                            ->max(3650)
                        ->end()
                        ->integerNode('purge_batch_size')
                            ->info('Rows deleted per statement by the purge (keyset batches).')
                            ->defaultValue(1000)
                            ->min(1)
                            ->max(100_000)
                        ->end()
                    ->end()
                ->end()
                ->append($this->brandNode())
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('table_prefix')
                            ->info('Prefix of the bundle table and index names (REQ-ORM-002): "app_" gives app_mail_kit_sent_mail.')
                            ->defaultValue('')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^[A-Za-z0-9_]*$/', $v) !== 1)
                                ->thenInvalid('nowo_mail_kit.doctrine.table_prefix must contain only letters, digits and underscores, got %s.')
                            ->end()
                        ->end()
                        ->scalarNode('entity_manager')
                            ->info('Doctrine ORM entity manager name (null = default).')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->info('Admin UI (REQ-UI-001 / REQ-UI-003): sent-mail log pages and the POST endpoints of the settings actions.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('false = no admin UI (no routes, no controller, no settings actions); the mailer, CLI and Twig stay available.')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('path_prefix')
                            ->info('URL prefix of the admin routes; protect it with security.access_control in the host.')
                            ->defaultValue(self::DEFAULT_PATH_PREFIX)
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || trim($v, '/ ') === '' || !str_starts_with($v, '/'))
                                ->thenInvalid('nowo_mail_kit.web_ui.path_prefix must be an absolute path such as "/admin/mail", got %s.')
                            ->end()
                        ->end()
                        ->scalarNode('layout_template')
                            ->info('Layout the pages extend. Null (default) = nowo_ui_kit.layouts.admin.')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('templates')
                            ->info('Per-page template overrides (page id => template): log_index, log_show. Extend @!NowoMailKitBundle/... and override a block.')
                            ->useAttributeAsKey('page')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->validate()
                                ->ifTrue(static fn (array $v): bool => array_diff(array_keys($v), array_keys(MailKitPages::TEMPLATES)) !== [])
                                ->then(static function (array $v): never {
                                    throw new InvalidConfigurationException(sprintf('Unknown nowo_mail_kit.web_ui.templates page(s) "%s"; allowed: %s.', implode('", "', array_diff(array_keys($v), array_keys(MailKitPages::TEMPLATES))), implode(', ', array_keys(MailKitPages::TEMPLATES))));
                                })
                            ->end()
                        ->end()
                        ->integerNode('page_size')
                            ->info('Rows per page of the sent-mail log (REQ-PERF-001).')
                            ->defaultValue(25)
                            ->min(5)
                            ->max(200)
                        ->end()
                        ->arrayNode('export')
                            ->info('CSV export of the sent-mail log (GET <path_prefix>/log/export, current filters and sort; streamed, formula-injection safe, no-store).')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')
                                    ->info('Expose the export route and the "Export CSV" action of the log list (same access as the list).')
                                    ->defaultTrue()
                                ->end()
                                ->integerNode('max_rows')
                                    ->info('Rows of one export at most (1 … 100 000); a longer result is truncated with a closing notice row (REQ-PERF-001). On MySQL / MariaDB (pdo_mysql buffers results) the whole result is held by the client before the first row is written.')
                                    ->defaultValue(SentMailCsvExporter::DEFAULT_MAX_ROWS)
                                    ->min(1)
                                    ->max(SentMailCsvExporter::MAX_ROWS_LIMIT)
                                ->end()
                                ->arrayNode('access_roles')
                                    ->info('Roles the export needs on top of the log access (any of them). Empty = whoever opens the log may export it.')
                                    ->scalarPrototype()->cannotBeEmpty()->end()
                                    ->defaultValue([])
                                ->end()
                                ->booleanNode('include_bodies')
                                    ->info('Offer an export with the stored text / HTML bodies (personal data). Needs log.store_bodies; never on by default.')
                                    ->defaultFalse()
                                ->end()
                                ->integerNode('body_batch_bytes')
                                    ->info('Approximate body bytes loaded with one query by an export with bodies (1 MiB … 64 MiB); bounds the memory of that export.')
                                    ->defaultValue(SentMailCsvExporter::BODY_BATCH_BYTES)
                                    ->min(1_048_576)
                                    ->max(67_108_864)
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->info('REQ-UI-002 private access of the admin UI.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('access_roles')
                            ->info('Roles that open the admin UI (any of them). Must not be empty unless access_checker is set or allow_unauthenticated is true.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                        ->end()
                        ->scalarNode('access_checker')
                            ->info('Service id implementing MailKitAccessCheckerInterface; null = role-based default.')
                            ->defaultNull()
                        ->end()
                        ->booleanNode('allow_unauthenticated')
                            ->info('DEV / DEMO only (refused without kernel.debug): open the UI without login.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static function (array $v): bool {
                            if (($v['allow_unauthenticated'] ?? false) === true || (is_string($v['access_checker'] ?? null) && $v['access_checker'] !== '')) {
                                return false;
                            }

                            return self::roles($v['access_roles'] ?? []) === [];
                        })
                        ->thenInvalid('nowo_mail_kit.security.access_roles must contain at least one non-empty role when no access_checker is set and allow_unauthenticated is false (an empty list would deny everybody).')
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => ($v['log']['enabled'] ?? true) === true && ($v['log']['map_entities'] ?? true) === false)
                ->thenInvalid('nowo_mail_kit.log.map_entities: false needs nowo_mail_kit.log.enabled: false (the log and its admin pages use the mapped entities).')
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => ($v['web_ui']['export']['include_bodies'] ?? false) === true && ($v['log']['store_bodies'] ?? false) !== true)
                ->thenInvalid('nowo_mail_kit.web_ui.export.include_bodies needs nowo_mail_kit.log.store_bodies: true (no bodies are stored otherwise).')
            ->end();

        return $treeBuilder;
    }

    /**
     * `brand` (spec 220 S7): the optional branded e-mail layout and its CID images.
     */
    private function brandNode(): ArrayNodeDefinition
    {
        /** @var ArrayNodeDefinition $node */
        $node = (new TreeBuilder('brand'))->getRootNode();
        /** @var ArrayNodeDefinition $colors */
        $colors = (new TreeBuilder('colors'))->getRootNode();
        $colors
            ->info('Fallback colours of the branded layout without a brand provider: #rrggbb only (lower-cased).')
            ->addDefaultsIfNotSet();
        $children = $colors->children();
        foreach (BrandedEmailHelper::DEFAULT_COLORS as $key => $default) {
            $children
                ->scalarNode($key)
                    ->defaultValue($default)
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^#[0-9a-fA-F]{6}\z/', $v) !== 1)
                        ->thenInvalid(sprintf('nowo_mail_kit.brand.colors.%s must be a #rrggbb colour, got %%s.', $key))
                    ->end()
                    ->validate()
                        ->always(static fn (string $v): string => strtolower($v))
                    ->end()
                ->end();
        }

        $node
            ->info('Spec 220: brand name, colours and logo (CID) of the optional @NowoMailKit/email/branded_layout templates, from a nowo-tech/brand-contracts BrandProviderInterface service when the app has one. "auto" or "off" is accepted as a shorthand for mode.')
            ->addDefaultsIfNotSet()
            ->beforeNormalization()
                ->ifString()
                ->then(static fn (string $v): array => ['mode' => $v])
            ->end()
            ->children()
                ->enumNode('mode')
                    ->info('auto: use the brand provider (and its asset reader) when one exists; the values below stay the fallback. off: ignore it.')
                    ->values([self::BRAND_AUTO, self::BRAND_OFF])
                    ->defaultValue(self::BRAND_AUTO)
                ->end()
                ->scalarNode('name')
                    ->info(sprintf('Brand name printed without a provider (null: none). At most %d characters, no control or bidi characters.', BrandedEmailText::MAX_LENGTH))
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => $v !== null && !BrandedEmailText::isSafeName($v))
                        ->thenInvalid(sprintf('nowo_mail_kit.brand.name must be a non-blank string of at most %d characters without control or bidi characters, got %%s.', BrandedEmailText::MAX_LENGTH))
                    ->end()
                    ->validate()
                        ->ifString()
                        ->then(static fn (string $v): string => trim($v))
                    ->end()
                ->end()
                ->append($colors)
                ->integerNode('max_image_bytes')
                    ->info('Largest brand image attached to one e-mail, in bytes (a larger one is left out).')
                    ->defaultValue(BrandedEmailHelper::DEFAULT_MAX_IMAGE_BYTES)
                    ->min(1024)
                    ->max(2_097_152)
                ->end()
                ->integerNode('max_total_bytes')
                    ->info('Largest total of the brand images of one e-mail, in bytes.')
                    ->defaultValue(BrandedEmailHelper::DEFAULT_MAX_TOTAL_BYTES)
                    ->min(1024)
                    ->max(5_242_880)
                ->end()
                ->integerNode('max_image_side')
                    ->info('Longest side of a brand image, in pixels, read from the image header.')
                    ->defaultValue(BrandedEmailHelper::DEFAULT_MAX_IMAGE_SIDE)
                    ->min(16)
                    ->max(8192)
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => $v['max_image_bytes'] > $v['max_total_bytes'])
                ->thenInvalid('nowo_mail_kit.brand.max_image_bytes must not exceed nowo_mail_kit.brand.max_total_bytes.')
            ->end();

        return $node;
    }

    /**
     * Non-empty role strings of a configured list.
     *
     * @return list<string>
     */
    public static function roles(mixed $roles): array
    {
        $list = [];
        foreach (is_array($roles) ? array_values($roles) : [] as $role) {
            if (is_string($role) && trim($role) !== '') {
                $list[] = trim($role);
            }
        }

        return $list;
    }

    /**
     * Lowercase strings of a configured list.
     *
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    public static function lower(array $values): array
    {
        $list = [];
        foreach ($values as $value) {
            $list[] = strtolower(trim((string) $value));
        }

        return $list;
    }
}
