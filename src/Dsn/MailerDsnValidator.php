<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Dsn;

use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Throwable;

use function array_map;
use function array_values;
use function in_array;
use function str_starts_with;
use function strtolower;
use function trim;

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

    /** Schemes whose `default` host means "the provider endpoint" (not a server name to guard). */
    private const SMTP_SCHEMES = ['smtp', 'smtps'];

    /** @var list<string> */
    private array $allowedSchemes;

    /**
     * @param list<string> $allowedSchemes accepted schemes (lowercase)
     * @param Transport|null $transportFactory `mailer.transport_factory` (null = no build check)
     */
    public function __construct(
        array $allowedSchemes = self::DEFAULT_SCHEMES,
        private DsnHostGuard $hostGuard = new DsnHostGuard(),
        private ?Transport $transportFactory = null,
    ) {
        $this->allowedSchemes = array_values(array_map(static fn (string $scheme): string => strtolower(trim($scheme)), $allowedSchemes));
    }

    /**
     * Null when the DSN is acceptable (or blank), else an error translation key.
     */
    public function validate(string $dsn): ?string
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

        $host = $parsed->getHost();
        if ($host !== 'default' || in_array($scheme, self::SMTP_SCHEMES, true)) {
            $error = $this->hostGuard->check($host);
            if ($error !== null) {
                return $error;
            }
        }

        if ($this->transportFactory instanceof Transport) {
            try {
                $this->transportFactory->fromString($dsn);
            } catch (UnsupportedSchemeException) {
                return self::UNSUPPORTED;
            } catch (Throwable) {
                return self::INVALID;
            }
        }

        return null;
    }

    /**
     * True when the DSN is non-blank and valid (never null://).
     */
    public function isDeliverable(?string $dsn): bool
    {
        $dsn = trim((string) $dsn);

        return $dsn !== '' && $this->validate($dsn) === null;
    }
}
