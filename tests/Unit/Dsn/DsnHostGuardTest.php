<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Dsn;

use Nowo\MailKitBundle\Dsn\DsnHostGuard;
use Nowo\OutboundUrlGuardBundle\Dns\HostnameDnsLookup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use const DNS_A;

final class DsnHostGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function literalHosts(): iterable
    {
        yield 'empty' => ['', DsnHostGuard::HOST_NOT_ALLOWED];
        yield 'percent' => ['ex%61mple.com', DsnHostGuard::HOST_NOT_ALLOWED];
        yield 'invalid label' => ['-bad-.example.com', DsnHostGuard::HOST_NOT_ALLOWED];
        yield 'localhost' => ['localhost', DsnHostGuard::HOST_NOT_ALLOWED];
        yield 'dot local' => ['mail.corp.local', DsnHostGuard::HOST_NOT_ALLOWED];
        yield 'loopback' => ['127.0.0.1', DsnHostGuard::HOST_NOT_ALLOWED];
        yield 'private' => ['10.1.2.3', DsnHostGuard::HOST_NOT_ALLOWED];
        yield 'ipv6 loopback' => ['[::1]', DsnHostGuard::HOST_NOT_ALLOWED];
        yield 'metadata ip' => ['169.254.169.254', DsnHostGuard::HOST_NOT_ALLOWED];
        yield 'metadata name' => ['metadata.google.internal', DsnHostGuard::HOST_NOT_ALLOWED];
        yield 'public ip' => ['8.8.8.8', null];
    }

    #[DataProvider('literalHosts')]
    public function testLiteralHosts(string $host, ?string $expected): void
    {
        self::assertSame($expected, (new DsnHostGuard(resolveDns: false))->check($host));
    }

    public function testAllowListAndPrivateSwitch(): void
    {
        self::assertNull((new DsnHostGuard(['Mailpit.'], resolveDns: false))->check('mailpit'));
        $private = new DsnHostGuard(allowPrivate: true);
        self::assertNull($private->check('10.0.0.5'));
        self::assertNull($private->check('relay.internal'));
        self::assertSame(DsnHostGuard::HOST_NOT_ALLOWED, $private->check('169.254.169.254'));
        self::assertNull((new DsnHostGuard(resolveDns: false))->check('smtp.example.com'));
        // CA I2: single-label names are internal, refused unless allow-listed, even without DNS.
        self::assertSame(DsnHostGuard::HOST_NOT_ALLOWED, (new DsnHostGuard(resolveDns: false))->check('redis'));
        self::assertNull((new DsnHostGuard(allowPrivate: true))->check('redis'));
    }

    public function testResolvedAddressesAreChecked(): void
    {
        $dns = $this->createMock(HostnameDnsLookup::class);
        $dns->method('dnsGetRecord')->willReturnCallback(static fn (string $host, int $type): array|false => match ($host . '/' . $type) {
            'public.example.com/' . DNS_A  => [['ip' => '93.184.216.34'], ['ip' => ''], 'junk'],
            'private.example.com/' . DNS_A => [['ip' => '192.168.1.10']],
            'meta.example.com/' . DNS_A    => [['ip' => '100.100.100.200']],
            default                        => false,
        });
        $dns->method('hostByNameL')->willReturnCallback(static fn (string $host): array|false => $host === 'legacy.example.com' ? ['93.184.216.35', ''] : false);
        $guard = new DsnHostGuard(dns: $dns);

        self::assertNull($guard->check('public.example.com'));
        self::assertNull($guard->check('legacy.example.com'));
        self::assertSame(DsnHostGuard::HOST_NOT_ALLOWED, $guard->check('private.example.com'));
        self::assertSame(DsnHostGuard::HOST_NOT_ALLOWED, $guard->check('meta.example.com'));
        self::assertSame(DsnHostGuard::HOST_UNRESOLVABLE, $guard->check('nowhere.example.com'));
        // CA L1: per-call override without DNS.
        self::assertNull($guard->check('nowhere.example.com', false));
    }
}
