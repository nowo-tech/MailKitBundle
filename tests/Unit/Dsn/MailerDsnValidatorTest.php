<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Dsn;

use Nowo\MailKitBundle\Dsn\DsnHostGuard;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\Tests\Support\MemoryMailStore;
use Nowo\MailKitBundle\Tests\Support\MemoryTransportFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;

final class MailerDsnValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function dsns(): iterable
    {
        yield 'blank' => ['  ', null];
        yield 'smtp public' => ['smtp://user:REDACTED-TEST@8.8.8.8:587', null];
        yield 'null' => ['null://null', MailerDsnValidator::NULL_TRANSPORT];
        yield 'unparsable' => ['not a dsn', MailerDsnValidator::INVALID];
        yield 'sendmail' => ['sendmail://default', MailerDsnValidator::SCHEME_NOT_ALLOWED];
        yield 'native' => ['native://default', MailerDsnValidator::SCHEME_NOT_ALLOWED];
        yield 'private smtp' => ['smtp://10.0.0.1:25', DsnHostGuard::HOST_NOT_ALLOWED];
        yield 'smtp default host is a host name' => ['smtp://default', null];
        yield 'smtp localhost' => ['smtp://localhost:1025', DsnHostGuard::HOST_NOT_ALLOWED];
        yield 'provider default host' => ['memory://default', null];
        yield 'no bridge' => ['resend+api://REDACTED-TEST@default', MailerDsnValidator::UNSUPPORTED];
        yield 'factory error' => ['memory://broken', MailerDsnValidator::INVALID];
    }

    #[DataProvider('dsns')]
    public function testValidate(string $dsn, ?string $expected): void
    {
        $validator = new MailerDsnValidator(
            ['SMTP', 'memory', 'resend+api'],
            new DsnHostGuard(resolveDns: false),
            new Transport([new EsmtpTransportFactory(), new MemoryTransportFactory(new MemoryMailStore())]),
        );

        self::assertSame($expected, $validator->validate($dsn));
    }

    public function testDeliverableAndWithoutFactory(): void
    {
        $validator = new MailerDsnValidator(hostGuard: new DsnHostGuard(resolveDns: false));
        self::assertFalse($validator->isDeliverable(null));
        self::assertFalse($validator->isDeliverable('null://null'));
        self::assertTrue($validator->isDeliverable('resend+api://REDACTED-TEST@default'));
        self::assertContains('smtp', MailerDsnValidator::DEFAULT_SCHEMES);
    }
}
