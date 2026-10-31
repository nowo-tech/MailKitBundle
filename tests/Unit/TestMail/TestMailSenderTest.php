<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\TestMail;

use Nowo\MailKitBundle\Dsn\DsnHostGuard;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\Mailer\RuntimeTransport;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\TestMail\TestMailGuard;
use Nowo\MailKitBundle\TestMail\TestMailOutcome;
use Nowo\MailKitBundle\TestMail\TestMailResult;
use Nowo\MailKitBundle\TestMail\TestMailSender;
use Nowo\MailKitBundle\Tests\Support\FakeSettings;
use Nowo\MailKitBundle\Tests\Support\MemoryMailStore;
use Nowo\MailKitBundle\Tests\Support\MemoryTransport;
use Nowo\MailKitBundle\Tests\Support\MemoryTransportFactory;
use Nowo\MailKitBundle\Tests\Support\StaticDsnProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class TestMailSenderTest extends TestCase
{
    private MemoryMailStore $store;

    protected function setUp(): void
    {
        $this->store = new MemoryMailStore();
    }

    public function testRecipientResolution(): void
    {
        $sender = $this->sender(new FakeSettings(['mailer.test_recipient' => 'qa@example.com']));
        self::assertSame('cli@example.com', $sender->recipient(' cli@example.com ', null));
        self::assertNull($sender->recipient('not-an-address', null));
        self::assertSame('qa@example.com', $sender->recipient(null, null));

        $bare = $this->sender(new FakeSettings());
        self::assertSame('user@example.com', $bare->recipient(null, new class {
            public function getEmail(): string
            {
                return 'user@example.com';
            }
        }));
        self::assertNull($bare->recipient(null, new class {
            public function getEmail(): int
            {
                return 42;
            }
        }));
        self::assertSame('id@example.com', $bare->recipient(null, new InMemoryUser('id@example.com', null)));
        self::assertNull($bare->recipient(null, new InMemoryUser('admin', null)));
        self::assertNull($bare->recipient(null, null));
    }

    public function testOutcomes(): void
    {
        self::assertSame(TestMailOutcome::NoRecipient, $this->sender(new FakeSettings())->sendMailer()->outcome);
        self::assertSame(TestMailOutcome::NoSender, $this->sender(new FakeSettings())->sendMailer('qa@example.com')->outcome);
        self::assertSame(TestMailOutcome::InvalidSender, $this->sender(new FakeSettings(['mailer.from' => 'root@localhost']))->sendMailer('qa@example.com')->outcome);
        self::assertSame(TestMailOutcome::Sent, $this->sender(new FakeSettings(['mailer.from' => 'root@localhost']), false)->sendMailer('qa@example.com')->outcome);

        $sent = $this->sender(new FakeSettings(['mailer.from' => 'noreply@example.com']))->sendMailer('qa@example.com');
        self::assertTrue($sent->isSent());
        self::assertSame(['%to%' => 'qa@example.com', '%error%' => ''], $sent->parameters());
        $message = $this->store->sentVia('fallback')[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('test_email.subject', $message->getSubject());
        self::assertStringContainsString('<p>test_email.body</p>', (string) $message->getHtmlBody());

        $failed = $this->sender(new FakeSettings(['mailer.from' => 'noreply@example.com']), dsn: 'memory://fail')->sendMailer('qa@example.com');
        self::assertSame(TestMailOutcome::Failed, $failed->outcome);
        self::assertStringContainsString('smtp://***@relay.example.com', (string) $failed->error);
    }

    public function testResendChannel(): void
    {
        self::assertSame(TestMailOutcome::NotConfigured, $this->sender(new FakeSettings(), resend: true)->sendResend('qa@example.com')->outcome);
        $result = $this->sender(new FakeSettings(['mailer.from' => 'noreply@example.com'], ['resend.api_key' => 're_REDACTED-TEST']), resend: true)->sendResend('qa@example.com');
        self::assertSame(TestMailOutcome::Failed, $result->outcome);
        self::assertStringContainsString('dsn.scheme_not_allowed', (string) $result->error);
    }

    public function testOutcomeKeysAndGuard(): void
    {
        self::assertSame('test_email.result.rate_limited', TestMailOutcome::RateLimited->messageKey());
        self::assertFalse((new TestMailResult(TestMailOutcome::Failed))->isSent());

        $guard = new TestMailGuard(new RateLimiterFactory(['id' => 't', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '1 minute'], new InMemoryStorage()));
        self::assertTrue($guard->allows('User:A'));
        self::assertFalse($guard->allows('user:a'));
        self::assertTrue($guard->allows('user:b'));
    }

    private function sender(FakeSettings $values, bool $publicDomain = true, ?string $dsn = null, bool $resend = false): TestMailSender
    {
        $settings  = new MailSettings($values, $resend);
        $factory   = new Transport([new MemoryTransportFactory($this->store)]);
        $transport = new RuntimeTransport(
            new MemoryTransport('fallback', $this->store),
            new StaticDsnProvider($dsn),
            new MailerDsnValidator(['memory'], new DsnHostGuard(resolveDns: false), $factory),
            $factory,
            new SenderPolicy($settings),
        );

        return new TestMailSender($transport, $settings, null, $publicDomain);
    }
}
