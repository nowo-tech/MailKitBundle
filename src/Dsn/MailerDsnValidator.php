<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Dsn;

use SensitiveParameter;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Throwable;

use function array_map;
use function array_values;
use function filter_var;
use function in_array;
use function is_string;
use function parse_str;
use function parse_url;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strpos;
use function strrpos;
use function strstr;
use function strtok;
use function strtolower;
use function substr;
use function trim;

use const FILTER_VALIDATE_BOOL;
use const PHP_URL_QUERY;

/**
 * Validates a mailer DSN entered in the admin UI before it is stored or used: an allow-listed scheme (never
 * sendmail / native, which run host processes, nor null, which drops every message), a parsable DSN, a host that
 * passes {@see DsnHostGuard} (SSRF), and — when the transport factory is available — a transport Symfony can build
 * (an unsupported scheme usually means a missing bridge such as symfony/resend-mailer). Building a transport does
 * not connect.
 *
 * Error results are translation keys of the `NowoMailKitBundle` domain; the DSN itself never appears in a message.
 */
final readonly class MailerDsnValidator
{
    /** Default allow-list of `dsn.allowed_schemes`: SMTP and the API / SMTP bridges of the common providers. */
    public const DEFAULT_SCHEMES = [
        'smtp',
        'smtps',
        'mailgun',
        'mailgun+api',
        'mailgun+https',
        'mailgun+smtp',
        'postmark',
        'postmark+api',
        'postmark+smtp',
        'sendgrid',
        'sendgrid+api',
        'sendgrid+smtp',
        'ses',
        'ses+api',
        'ses+https',
        'ses+smtp',
        'brevo',
        'brevo+api',
        'brevo+smtp',
        'resend',
        'resend+api',
        'resend+smtp',
        'mailjet',
        'mailjet+api',
        'mailjet+smtp',
        'mailpace',
        'mailpace+api',
        'mailpace+smtp',
        'scaleway',
        'scaleway+api',
        'scaleway+smtp',
    ];

    /** Error key: the DSN cannot be parsed. */
    public const INVALID = 'dsn.invalid';

    /** Error key: null:// would drop every message. */
    public const NULL_TRANSPORT = 'dsn.null_transport';

    /** Error key: the scheme is not in `dsn.allowed_schemes`. */
    public const SCHEME_NOT_ALLOWED = 'dsn.scheme_not_allowed';

    /** Error key: no installed transport factory supports the scheme. */
    public const UNSUPPORTED = 'dsn.unsupported';

    /** Error key: a query option is refused (it could shape the provider endpoint, or has an unexpected value). */
    public const OPTION_NOT_ALLOWED = 'dsn.option_not_allowed';

    /** Schemes whose `default` host means "the provider endpoint" (not a server name to guard). */
    private const SMTP_SCHEMES = ['smtp', 'smtps'];

    /**
     * Accepted `region` values per scheme family (the bridges build their API host from it). AWS SES: region syntax.
     *
     * @var array<string, list<string>|string>
     */
    private const REGIONS = [
        'mailgun'  => ['us', 'eu'],
        'sendgrid' => ['global', 'eu'],
        'scaleway' => ['fr-par', 'nl-ams', 'pl-waw'],
        'ses'      => self::AWS_REGION,
    ];

    /** AWS region syntax (anchored, no trailing newline). */
    public const AWS_REGION = '/^[a-z]{2}(-gov)?-[a-z]+-\\d\\z/D';

    /** Error key: a TLS check is switched off (`verify_peer=0`) without `dsn.allow_insecure_tls`. */
    public const INSECURE_TLS = 'dsn.insecure_tls';

    /** Options that switch TLS checks off. */
    private const TLS_OPTIONS = ['verify_peer', 'verify_peer_name'];

    /** Options carrying credentials (base64-like values: `/`, `+`, `=` allowed, never an endpoint). */
    private const CREDENTIAL_OPTIONS = ['session_token'];

    /** Characters refused in any other option value (they could move the endpoint host, port, path or query). */
    private const UNSAFE_VALUE = '#[@/?\#:\\\\\s\x00-\x1F\x7F]#';

    /** Host suffixes of the provider endpoints: an API endpoint elsewhere is guarded like an SMTP host. */
    private const PROVIDER_HOSTS = ['mailgun.net', 'sendgrid.com', 'sendgrid.net', 'amazonaws.com', 'resend.com', 'postmarkapp.com', 'brevo.com', 'sendinblue.com', 'mailjet.com', 'mailpace.com', 'scw.cloud', 'scaleway.com'];

    /** @var list<string> */
    private array $allowedSchemes;

    /**
     * @param list<string> $allowedSchemes accepted schemes (lowercase)
     * @param Transport|null $transportFactory `mailer.transport_factory` (null = no build check)
     * @param bool $allowInsecureTls `dsn.allow_insecure_tls` (dev only): accept `verify_peer=0` / `verify_peer_name=0`
     */
    public function __construct(
        array $allowedSchemes = self::DEFAULT_SCHEMES,
        private DsnHostGuard $hostGuard = new DsnHostGuard(),
        private ?Transport $transportFactory = null,
        private bool $allowInsecureTls = false,
    ) {
        $this->allowedSchemes = array_values(array_map(static fn (string $scheme): string => strtolower(trim($scheme)), $allowedSchemes));
    }

    /**
     * Null when the DSN is acceptable (or blank), else an error translation key.
     *
     * @param bool $resolveDns false = syntax and configuration only (no DNS lookup), for cheap readiness checks
     */
    public function validate(#[SensitiveParameter] string $dsn, bool $resolveDns = true): ?string
    {
        $dsn = trim($dsn);
        if ($dsn === '') {
            return null;
        }
        if (str_starts_with(strtolower($dsn), 'null:')) {
            return self::NULL_TRANSPORT;
        }

        try {
            $parsed = Dsn::fromString($dsn);
        } catch (Throwable) {
            return self::INVALID;
        }

        $scheme = strtolower($parsed->getScheme());
        if (!in_array($scheme, $this->allowedSchemes, true)) {
            return self::SCHEME_NOT_ALLOWED;
        }
        if (!$this->optionsAllowed($scheme, $dsn)) {
            return self::OPTION_NOT_ALLOWED;
        }
        if (!$this->allowInsecureTls) {
            foreach (self::TLS_OPTIONS as $option) {
                $value = $parsed->getOption($option);
                if ($value !== null && filter_var($value, FILTER_VALIDATE_BOOL) === false) {
                    return self::INSECURE_TLS;
                }
            }
        }

        $host = $parsed->getHost();
        if ($host !== 'default' || in_array($scheme, self::SMTP_SCHEMES, true)) {
            $error = $this->hostGuard->check($host, $resolveDns ? null : false);
            if ($error !== null) {
                return $error;
            }
        }

        if ($this->transportFactory instanceof Transport) {
            try {
                $transport = $this->transportFactory->fromString($dsn);
            } catch (UnsupportedSchemeException) {
                return self::UNSUPPORTED;
            } catch (Throwable) {
                return self::INVALID;
            }

            return $this->checkEndpoint((string) $transport, $resolveDns);
        }

        return null;
    }

    /**
     * Endpoint host the built transport will connect to (from its `scheme://host[:port]…` string), or null.
     */
    public static function endpointHost(string $transport): ?string
    {
        $position = strpos($transport, '://');
        if ($position === false) {
            return null;
        }
        $scheme    = strtolower(substr($transport, 0, $position));
        $authority = (string) strtok(substr($transport, $position + 3), '/?#');
        if ($authority === '') {
            return null;
        }
        if (str_contains($authority, '@')) {
            // Bridges whose string carries more than the host (CA N2): Scaleway `endpoint@project`, SES `accessKey@region`
            // (default endpoint `email.<region>.amazonaws.com`) or `accessKey@host[:port]` (custom endpoint).
            $at = strrpos($authority, '@');
            if (str_starts_with($scheme, 'scaleway')) {
                $authority = (string) strstr($authority, '@', true);
            } elseif (str_starts_with($scheme, 'ses')) {
                $authority = substr($authority, $at + 1);
                if (preg_match(self::AWS_REGION, $authority) === 1) {
                    return 'email.' . $authority . '.amazonaws.com';
                }
            } else {
                return null;
            }
            if ($authority === '' || str_contains($authority, '@')) {
                return null;
            }
        }
        if (str_starts_with($authority, '[')) {
            $end = strpos($authority, ']');

            return $end === false ? null : substr($authority, 0, $end + 1);
        }
        $colon = strrpos($authority, ':');

        return $colon === false ? $authority : substr($authority, 0, $colon);
    }

    /**
     * Second line of defence (CA M1): the host the built transport really targets must be a provider host, the
     * literal `default`, or pass the host guard.
     */
    private function checkEndpoint(string $transport, bool $resolveDns): ?string
    {
        $host = self::endpointHost($transport);
        if ($host === null) {
            return self::INVALID;
        }
        $lower = strtolower(rtrim($host, '.'));
        if ($lower === 'default') {
            return null;
        }
        foreach (self::PROVIDER_HOSTS as $suffix) {
            if ($lower === $suffix || str_ends_with($lower, '.' . $suffix)) {
                return null;
            }
        }

        return $this->hostGuard->check($host, $resolveDns ? null : false);
    }

    /**
     * Query options: `region` only from the per-bridge allow-list; no option value may contain characters that could
     * move the endpoint (`@ / ? # : \`, whitespace, control characters), except credential options.
     */
    private function optionsAllowed(string $scheme, string $dsn): bool
    {
        $query = parse_url($dsn, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return true;
        }
        parse_str($query, $options);
        $family = (string) strtok($scheme, '+');
        foreach ($options as $name => $value) {
            if (!is_string($value)) {
                return false;
            }
            $name = strtolower((string) $name);
            if ($name === 'region') {
                $allowed = self::REGIONS[$family] ?? [];
                if (is_string($allowed) ? preg_match($allowed, $value) !== 1 : !in_array($value, $allowed, true)) {
                    return false;
                }

                continue;
            }
            if (in_array($name, self::CREDENTIAL_OPTIONS, true) ? preg_match('/[\s\x00-\x1F\x7F]/', $value) === 1 : preg_match(self::UNSAFE_VALUE, $value) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when the DSN is non-blank and valid (never null://).
     */
    public function isDeliverable(#[SensitiveParameter] ?string $dsn): bool
    {
        $dsn = trim((string) $dsn);

        return $dsn !== '' && $this->validate($dsn) === null;
    }
}
