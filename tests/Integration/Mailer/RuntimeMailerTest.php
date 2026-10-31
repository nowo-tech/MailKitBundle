<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Mailer;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\Dsn\DsnSource;
use Nowo\MailKitBundle\Entity\SentMail;
use Nowo\MailKitBundle\Log\SentMailStatus;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\MailKitBundle\Mailer\MailKitMailer;
use Nowo\MailKitBundle\Mailer\RuntimeTransport;
use Nowo\MailKitBundle\Readiness\OutboundMailReadiness;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Support\StaticDsnProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * End to end: the framework MailerInterface (decorated) sends through the DSN stored in Instance Settings, falls back
 * to framework.mailer.dsn, applies the runtime sender, logs every outcome and reacts to settings changes.
 */
final class RuntimeMailerTest extends KernelTestCase
{
    public function testFallsBackToTheFrameworkTransportUntilADsnIsStored(): void
    {
        $kernel = $this->bootKernel();
        $mailer = $this->mailer($kernel);
        self::assertInstanceOf(MailKitMailer::class, $this->container($kernel)->get('test.mail_kit.mailer'));

        $mailer->send((new Email())->from('app@example.com')->to('a@example.com')->subject('Hello')->text('Hi'));
        self::assertCount(1, $this->store($kernel)->sentVia('fallback'));

        $this->settings($kernel)->save('mailer', ['dsn' => 'memory://default', 'from' => 'noreply@example.com', 'from_name' => 'Acme', 'reply_to' => 'help@example.com']);
        $mailer->send((new Email())->to('b@example.com')->subject('Runtime')->text('Hi'));

        $sent = $this->store($kernel)->sentVia('default');
        self::assertCount(1, $sent);
        $original = $sent[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $original);
        self::assertSame('"Acme" <noreply@example.com>', $original->getFrom()[0]->toString());
        self::assertSame('help@example.com', $original->getReplyTo()[0]->getAddress());
        self::assertFalse($original->getHeaders()->has(MailHeaders::LOG_ID));

        $rows = $this->connection($kernel)->fetchAllAssociative('SELECT status, transport, subject, attempts FROM mail_kit_sent_mail ORDER BY queued_at, subject');
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame(SentMailStatus::Sent->value, $row['status']);
            self::assertSame(1, (int) $row['attempts']);
        }
        self::assertContains('memory', array_column($rows, 'transport'));
        self::assertContains('default', array_column($rows, 'transport'));
    }

    public function testANamedTransportKeepsTheFrameworkTransport(): void
    {
        $kernel = $this->bootKernel();
        $this->settings($kernel)->save('mailer', ['dsn' => 'memory://default']);
        $email = (new Email())->from('app@example.com')->to('a@example.com')->subject('Named')->text('Hi');
        $email->getHeaders()->addTextHeader('X-Transport', 'main');

        $this->mailer($kernel)->send($email);

        self::assertCount(1, $this->store($kernel)->sentVia('fallback'));
    }

    public function testAFailureIsLoggedScrubbedAndRethrown(): void
    {
        $kernel = $this->bootKernel(['log' => ['store_bodies' => true]]);
        $this->settings($kernel)->save('mailer', ['dsn' => 'memory://fail', 'from' => 'noreply@example.com']);
        $email = (new Email())->to('a@example.com')->subject('Broken')->text('code 123456')->html('<p>code 123456</p>');
        MailHeaders::tag($email, 'otp');
        MailHeaders::redact($email, '123456');

        try {
            $this->mailer($kernel)->send($email);
            self::fail('The transport error must reach the caller.');
        } catch (TransportException $e) {
            self::assertStringContainsString('relay.example.com', $e->getMessage());
        }

        $row = $this->connection($kernel)->fetchAssociative('SELECT * FROM mail_kit_sent_mail');
        self::assertIsArray($row);
        self::assertSame('failed', $row['status']);
        self::assertSame('otp', $row['tag']);
        self::assertStringNotContainsString('REDACTED-TEST', (string) $row['error']);
        self::assertStringContainsString('smtp://***@relay.example.com', (string) $row['error']);
        $body = $this->connection($kernel)->fetchAssociative('SELECT * FROM mail_kit_sent_mail_body');
        self::assertIsArray($body);
        self::assertSame('code [redacted]', $body['text_body']);
        self::assertSame('<p>code [redacted]</p>', $body['html_body']);
    }

    public function testARefusedDsnFromAHostProviderFailsClosed(): void
    {
        $kernel = $this->bootKernel(['mailer' => ['dsn_provider' => StaticDsnProvider::class]], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set(StaticDsnProvider::class)->args(['smtp://127.0.0.1:25'])->public();
        });
        $readiness = $this->service($kernel, OutboundMailReadiness::class);
        self::assertFalse($readiness->isOutboundMailReady());
        self::assertSame('dsn.host_not_allowed', $readiness->status()->error);
        self::assertSame(DsnSource::Custom, $readiness->status()->source);

        try {
            $this->mailer($kernel)->send((new Email())->from('app@example.com')->to('a@example.com')->subject('Refused')->text('Hi'));
            self::fail('A refused DSN must not fall back silently.');
        } catch (TransportException $e) {
            self::assertStringContainsString('dsn.host_not_allowed', $e->getMessage());
            self::assertStringNotContainsString('127.0.0.1', $e->getMessage());
        }
        self::assertSame([], $this->store($kernel)->sent);
        self::assertSame('failed', $this->connection($kernel)->fetchOne('SELECT status FROM mail_kit_sent_mail'));
    }

    public function testWithoutMessengerNoQueuedRowIsWritten(): void
    {
        $kernel = $this->bootKernel(without: ['messenger']);
        $this->mailer($kernel)->send((new Email())->from('app@example.com')->to('a@example.com')->subject('Sync')->text('Hi'));

        $rows = $this->connection($kernel)->fetchAllAssociative('SELECT status, attempts FROM mail_kit_sent_mail');
        self::assertSame([['status' => 'sent', 'attempts' => 1]], array_map(static fn (array $r): array => ['status' => $r['status'], 'attempts' => (int) $r['attempts']], $rows));
    }

    public function testDecorationCanBeSwitchedOff(): void
    {
        $kernel = $this->bootKernel(['mailer' => ['decorate' => false]]);
        self::assertNotInstanceOf(MailKitMailer::class, $this->container($kernel)->get('test.mail_kit.mailer'));
        $this->settings($kernel)->save('mailer', ['dsn' => 'memory://default', 'from' => 'noreply@example.com']);

        $this->service($kernel, RuntimeTransport::class)->send((new Email())->to('a@example.com')->subject('Direct')->text('Hi'));
        self::assertCount(1, $this->store($kernel)->sentVia('default'));
    }

    public function testTwigFunctionsExposeTheRedactedStatus(): void
    {
        $kernel   = $this->bootKernel();
        $twig     = $this->service($kernel, Environment::class);
        $template = $twig->createTemplate('{{ nowo_mail_kit_outbound_mail_ready() ? "ready" : "not ready" }}|{{ nowo_mail_kit_status().label }}|{{ nowo_mail_kit_status().source.value }}');
        self::assertSame('not ready|default|fallback', $template->render());

        $this->settings($kernel)->save('mailer', ['dsn' => 'memory://user:REDACTED-TEST@default']);
        self::assertSame('ready|memory://default|settings', $template->render());
    }

    public function testSentMailEntityIsMapped(): void
    {
        $kernel = $this->bootKernel(['doctrine' => ['table_prefix' => 'app_']]);
        $this->mailer($kernel)->send((new Email())->from('app@example.com')->to('a@example.com')->subject('Prefixed')->text('Hi'));

        self::assertSame(1, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM app_mail_kit_sent_mail'));
        $doctrine = $this->container($kernel)->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $em = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertSame('app_mail_kit_sent_mail', $em->getClassMetadata(SentMail::class)->getTableName());
    }
}
