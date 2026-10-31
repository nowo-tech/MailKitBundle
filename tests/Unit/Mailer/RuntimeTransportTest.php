<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Mailer;

use Nowo\MailKitBundle\Dsn\DsnHostGuard;
use Nowo\MailKitBundle\Dsn\DsnSource;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\Dsn\RuntimeDsn;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\MailKitBundle\Mailer\MailKitMailer;
use Nowo\MailKitBundle\Mailer\RuntimeTransport;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Tests\Support\FakeSettings;
use Nowo\MailKitBundle\Tests\Support\MemoryMailStore;
use Nowo\MailKitBundle\Tests\Support\MemoryTransport;
use Nowo\MailKitBundle\Tests\Support\MemoryTransportFactory;
use Nowo\MailKitBundle\Tests\Support\StaticDsnProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class RuntimeTransportTest extends TestCase
{
    private MemoryMailStore $store;

    protected function setUp(): void
    {
        $this->store = new MemoryMailStore();
    }

    public function testCachesTheTransportPerDsnAndResets(): void
    {
        $provider  = new StaticDsnProvider('memory://one');
        $transport = $this->transport($provider);

        $transport->send($this->email());
        $provider->dsn = 'memory://two';
        $transport->send($this->email());
        $transport->send($this->email());
        $transport->reset();
        $transport->send($this->email());

        self::assertCount(1, $this->store->sentVia('one'));
        self::assertCount(3, $this->store->sentVia('two'));
        self::assertSame('memory://two', $transport->currentLabel());
        self::assertSame('mail-kit://runtime', (string) $transport);
        $provider->dsn = null;
        self::assertSame('default', $transport->currentLabel());
    }

    public function testFallbackRawMessagesAndSendVia(): void
    {
        $transport = $this->transport(new StaticDsnProvider());
        $raw       = new RawMessage('raw');
        $transport->send($raw, new Envelope(new Address('a@example.com'), [new Address('b@example.com')]));
        self::assertCount(1, $this->store->sentVia('fallback'));

        $transport->sendVia(new RuntimeDsn('memory://via', DsnSource::Resend), $this->email());
        self::assertCount(1, $this->store->sentVia('via'));
    }

    public function testRefusedDsnFailsWithTheErrorKeyOnly(): void
    {
        $transport = $this->transport(new StaticDsnProvider('smtp://user:REDACTED-TEST@10.0.0.1'));
        self::assertSame(DsnHostGuard::HOST_NOT_ALLOWED, $transport->check(new RuntimeDsn('smtp://user:REDACTED-TEST@10.0.0.1')));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('mailer settings was refused (dsn.host_not_allowed)');
        $transport->send($this->email());
    }

    public function testRefusedResendDsnNamesResend(): void
    {
        $this->expectExceptionMessage('Resend settings was refused (dsn.scheme_not_allowed)');
        $this->transport(new StaticDsnProvider())->sendVia(new RuntimeDsn('resend+api://k@default', DsnSource::Resend), $this->email());
    }

    public function testFailuresAreRethrown(): void
    {
        $this->expectException(TransportException::class);
        $this->transport(new StaticDsnProvider('memory://fail'))->send($this->email());
    }

    public function testTheMailerDecoratorAppliesTheSenderAndDelegates(): void
    {
        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::exactly(2))->method('send');
        $policy = new SenderPolicy(new MailSettings(new FakeSettings(['mailer.from' => 'noreply@example.com'])));

        $email = new Email();
        (new MailKitMailer($inner, $policy, null, true))->send($email);
        self::assertSame('noreply@example.com', $email->getFrom()[0]->getAddress());
        self::assertFalse($email->getHeaders()->has(MailHeaders::LOG_ID));

        (new MailKitMailer($inner, $policy))->send(new RawMessage('raw'));
    }

    private function transport(StaticDsnProvider $provider): RuntimeTransport
    {
        $factory = new Transport([new MemoryTransportFactory($this->store)]);

        return new RuntimeTransport(
            new MemoryTransport('fallback', $this->store),
            $provider,
            new MailerDsnValidator(['memory', 'smtp'], new DsnHostGuard(resolveDns: false), $factory),
            $factory,
            new SenderPolicy(new MailSettings(new FakeSettings(['mailer.from' => 'noreply@example.com']))),
        );
    }

    private function email(): Email
    {
        return (new Email())->to('a@example.com')->subject('S')->text('t');
    }
}
