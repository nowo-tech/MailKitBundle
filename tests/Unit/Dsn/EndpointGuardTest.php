<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Dsn;

use Nowo\MailKitBundle\Dsn\DsnHostGuard;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\Tests\Support\MemoryMailStore;
use Nowo\MailKitBundle\Tests\Support\MemoryTransportFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunTransportFactory;
use Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridTransportFactory;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;

/**
 * Regression tests of the SEC-004 audit CA finding M1: provider `@default` DSNs whose query options shape the API
 * endpoint (`?region=x@127.0.0.1:8443/`) must never reach an internal host.
 */
final class EndpointGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function dsns(): iterable
    {
        // The audit PoCs (poc_dsn.php): userinfo / port / path injected through `region`.
        yield 'mailgun region injection' => ['mailgun+api://REDACTED-TEST:example.com@default?region=x@127.0.0.1:8443/', MailerDsnValidator::OPTION_NOT_ALLOWED];
        yield 'sendgrid region injection' => ['sendgrid+api://REDACTED-TEST@default?region=x@10.0.0.5:9200/', MailerDsnValidator::OPTION_NOT_ALLOWED];
        yield 'ses region injection' => ['ses+api://REDACTED-TEST:REDACTED-TEST@default?region=x@127.0.0.1:8443/', MailerDsnValidator::OPTION_NOT_ALLOWED];
        yield 'mailgun unknown region' => ['mailgun+api://REDACTED-TEST:example.com@default?region=ap', MailerDsnValidator::OPTION_NOT_ALLOWED];
        yield 'region on a bridge without regions' => ['postmark+api://REDACTED-TEST@default?region=eu', MailerDsnValidator::OPTION_NOT_ALLOWED];
        yield 'array option' => ['mailgun+api://REDACTED-TEST:example.com@default?region[]=eu', MailerDsnValidator::OPTION_NOT_ALLOWED];
        yield 'host-shaping characters in another option' => ['smtp://8.8.8.8:587?local_domain=a@10.0.0.5', MailerDsnValidator::OPTION_NOT_ALLOWED];
        yield 'colon in an option' => ['smtp://8.8.8.8:587?local_domain=10.0.0.5:25', MailerDsnValidator::OPTION_NOT_ALLOWED];
        yield 'whitespace in a credential option' => ['smtp://8.8.8.8:587?session_token=a%20b', MailerDsnValidator::OPTION_NOT_ALLOWED];
        // Accepted: allow-listed regions and harmless options.
        yield 'mailgun eu' => ['mailgun+api://REDACTED-TEST:example.com@default?region=eu', null];
        yield 'mailgun default endpoint' => ['mailgun+api://REDACTED-TEST:example.com@default', null];
        yield 'sendgrid eu' => ['sendgrid+api://REDACTED-TEST@default?region=eu', null];
        yield 'ses aws region syntax (no bridge installed)' => ['ses+api://REDACTED-TEST:REDACTED-TEST@default?region=eu-west-1', MailerDsnValidator::UNSUPPORTED];
        yield 'credential option with base64 characters' => ['smtp://8.8.8.8:587?session_token=ab/c%2Bd==', null];
        yield 'plain option' => ['smtp://8.8.8.8:587?local_domain=mail.example.com', null];
        yield 'option value with letters s' => ['smtp://8.8.8.8:587?verify_peer=false', MailerDsnValidator::INSECURE_TLS];
        yield 'backslash in an option' => ['smtp://8.8.8.8:587?local_domain=a%5Cb', MailerDsnValidator::OPTION_NOT_ALLOWED];
        // Second line of defence: the real endpoint of the built transport.
        yield 'mailgun private host in place of default' => ['mailgun+api://REDACTED-TEST:example.com@10.0.0.5', DsnHostGuard::HOST_NOT_ALLOWED];
        yield 'endpoint on a public non-provider host' => ['memory://mail.example.com', null];
        yield 'unreadable endpoint' => ['memory://weird', MailerDsnValidator::INVALID];
    }

    #[DataProvider('dsns')]
    public function testValidate(string $dsn, ?string $expected): void
    {
        $factory = new Transport([
            new EsmtpTransportFactory(),
            new MailgunTransportFactory(null, new MockHttpClient()),
            new SendgridTransportFactory(null, new MockHttpClient()),
            new MemoryTransportFactory(new MemoryMailStore()),
        ]);
        $schemes = [...MailerDsnValidator::DEFAULT_SCHEMES, 'memory'];

        self::assertSame($expected, (new MailerDsnValidator($schemes, new DsnHostGuard(['weird'], resolveDns: false), $factory))->validate($dsn));
    }

    public function testEndpointHost(): void
    {
        self::assertSame('api.eu.mailgun.net', MailerDsnValidator::endpointHost('mailgun+https://api.eu.mailgun.net?domain=example.com'));
        self::assertSame('smtp.example.com', MailerDsnValidator::endpointHost('smtp://smtp.example.com:587'));
        self::assertSame('[::1]', MailerDsnValidator::endpointHost('smtp://[::1]:25'));
        self::assertSame('default', MailerDsnValidator::endpointHost('memory://default'));
        self::assertNull(MailerDsnValidator::endpointHost('[::1'));
        self::assertNull(MailerDsnValidator::endpointHost('smtp://[::1'));
        self::assertNull(MailerDsnValidator::endpointHost('mailgun+https://api.x@10.0.0.5:8443/path'));
        self::assertNull(MailerDsnValidator::endpointHost('smtp://'));
    }
}
