<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Mailer;

use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\MailKitBundle\Mailer\RuntimeTransport;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Integration\Kernel\MailKitTestKernel;
use Nowo\MailKitBundle\Tests\Support\StaticDsnProvider;
use Nowo\MailKitBundle\Tests\Support\StaticSenderProvider;
use Nowo\MailKitBundle\Tests\Support\TrackingKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Messenger\MessageHandler;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mime\Email;

use function serialize;
use function unserialize;

/**
 * Audit NU-H1: a Messenger retry re-sends the very SendEmailMessage the failed attempt handled (serialized again by the
 * retry / failure transport). The runtime transport must never change the caller's message, so the retry still
 * carries the internal headers: the queued log row id, the tag, the redaction values and the tracking opt-outs.
 */
final class MessengerRetryTest extends KernelTestCase
{
    use TrackingKernelTrait;

    public function testARetryKeepsTheLogRowTheTagAndTheRedaction(): void
    {
        $kernel = $this->boot(['log' => ['store_bodies' => true]], null);
        $email  = (new Email())->from('app@example.com')->to('user@example.com')->subject('Reset')
            ->html('<a href="https://app.example.com/reset-password/SECRET-TOKEN-1234">Reset</a>')->text('Reset: SECRET-TOKEN-1234');
        MailHeaders::tag($email, 'password_reset');
        MailHeaders::redact($email, 'SECRET-TOKEN-1234');
        $logId = $this->service($kernel, SentMailRecorder::class)->queued($email);
        self::assertNotNull($logId);
        $email->getHeaders()->addTextHeader(MailHeaders::LOG_ID, $logId);

        $sent = $this->retry($kernel, $email);

        $db = $this->connection($kernel);
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'), 'The retry updates the queued row.');
        self::assertSame(['sent', 'password_reset', $logId, 2], [
            $db->fetchOne('SELECT status FROM mail_kit_sent_mail'),
            $db->fetchOne('SELECT tag FROM mail_kit_sent_mail'),
            $db->fetchOne('SELECT id FROM mail_kit_sent_mail'),
            (int) $db->fetchOne('SELECT attempts FROM mail_kit_sent_mail'),
        ]);
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail_body'));
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM mail_kit_sent_mail_body WHERE html_body LIKE '%SECRET-TOKEN%' OR text_body LIKE '%SECRET-TOKEN%'"), 'Never stored unredacted.');
        foreach ([MailHeaders::LOG_ID, MailHeaders::TAG, MailHeaders::REDACT] as $header) {
            self::assertFalse($sent->getHeaders()->has($header), 'Delivered without ' . $header);
            self::assertTrue($email->getHeaders()->has($header), 'The caller keeps ' . $header);
        }
    }

    public function testARetryOfASecurityNoTrackOrRedactedMailIsNeverTracked(): void
    {
        $kernel = $this->boot(['log' => ['enabled' => false], 'tracking' => self::tracking()], 'memory://fail');
        $cases  = [];
        $reset  = $this->email();
        MailHeaders::template($reset, 'password_reset');
        $cases[] = $reset;
        $noTrack = $this->email();
        MailHeaders::noTracking($noTrack);
        $cases[]  = $noTrack;
        $redacted = $this->email();
        MailHeaders::redact($redacted, 'one-time-123');
        $cases[] = $redacted;

        foreach ($cases as $email) {
            $this->service($kernel, StaticDsnProvider::class)->dsn = 'memory://fail';
            $sent                                                  = $this->retry($kernel, $email);
            self::assertStringNotContainsString('/e/o/', (string) $sent->getHtmlBody());
            self::assertStringNotContainsString('/s/', (string) $sent->getHtmlBody());
        }
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM mail_kit_tracking_send'));
    }

    /**
     * First attempt fails, the message goes through serialization (retry transport), the second attempt is delivered.
     */
    private function retry(MailKitTestKernel $kernel, Email $email): Email
    {
        $handler       = new MessageHandler($this->service($kernel, RuntimeTransport::class));
        $provider      = $this->service($kernel, StaticDsnProvider::class);
        $message       = new SendEmailMessage($email);
        $provider->dsn = 'memory://fail';
        try {
            $handler($message);
            self::fail('The first attempt must fail.');
        } catch (TransportExceptionInterface) {
            self::addToAssertionCount(1);
        }
        /** @var SendEmailMessage $message */
        $message       = unserialize(serialize($message));
        $provider->dsn = 'memory://default';
        $handler($message);
        $sent                       = $this->store($kernel)->sentVia('default');
        $this->store($kernel)->sent = [];
        $delivered                  = $sent[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $delivered);

        return $delivered;
    }

    private function email(): Email
    {
        return (new Email())->from('app@example.com')->to('user@example.com')->subject('Hi')
            ->html('<a href="https://app.example.com/issues/1">Issue</a> <a href="https://app.example.com/i/one-time-123">Code</a>');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config, ?string $dsn): MailKitTestKernel
    {
        return $this->bootKernel(
            $config + ['mailer' => ['dsn_provider' => StaticDsnProvider::class, 'sender_provider' => StaticSenderProvider::class], 'web_ui' => ['enabled' => false]],
            without: ['instance_settings'],
            routes: static function (): void {
            },
            extra: self::trackingServices(static function (ContainerConfigurator $container) use ($dsn): void {
                $container->services()->set(StaticDsnProvider::class)->args([$dsn])->public();
                $container->services()->set(StaticSenderProvider::class);
            }),
        );
    }
}
