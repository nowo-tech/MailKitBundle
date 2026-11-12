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
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Messenger\MessageHandler;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mime\Email;

use function serialize;
use function unserialize;

/**
 * 1.9.1: a Messenger retry re-sends the very SendEmailMessage the failed attempt handled (serialized again by the
 * retry / failure transport). The runtime transport must never change the caller's message, so the retry still carries
 * the internal headers: the queued log row id, the tag and the redaction values (stored bodies stay redacted).
 */
final class MessengerRetryTest extends KernelTestCase
{
    public function testARetryKeepsTheLogRowTheTagAndTheRedaction(): void
    {
        $kernel = $this->boot();
        $email  = (new Email())->from('app@example.com')->to('user@example.com')->subject('Reset')
            ->html('<a href="https://app.example.com/reset-password/SECRET-TOKEN-1234">Reset</a>')->text('Reset: SECRET-TOKEN-1234');
        MailHeaders::tag($email, 'password_reset');
        MailHeaders::redact($email, 'SECRET-TOKEN-1234');
        $logId = $this->service($kernel, SentMailRecorder::class)->queued($email);
        self::assertNotNull($logId);
        $email->getHeaders()->addTextHeader(MailHeaders::LOG_ID, $logId);

        $handler  = new MessageHandler($this->service($kernel, RuntimeTransport::class));
        $provider = $this->service($kernel, StaticDsnProvider::class);
        $message  = new SendEmailMessage($email);
        try {
            $handler($message);
            self::fail('The first attempt must fail.');
        } catch (TransportExceptionInterface) {
            self::addToAssertionCount(1);
        }
        // What the retry transport does: serialize the message the failed attempt handled, then handle it again.
        /** @var SendEmailMessage $message */
        $message       = unserialize(serialize($message));
        $provider->dsn = 'memory://default';
        $handler($message);

        $db = $this->connection($kernel);
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'), 'The retry updates the queued row.');
        self::assertSame(['sent', 'password_reset', $logId, 2], [
            $db->fetchOne('SELECT status FROM mail_kit_sent_mail'),
            $db->fetchOne('SELECT tag FROM mail_kit_sent_mail'),
            $db->fetchOne('SELECT id FROM mail_kit_sent_mail'),
            (int) $db->fetchOne('SELECT attempts FROM mail_kit_sent_mail'),
        ]);
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM mail_kit_sent_mail_body WHERE html_body LIKE '%SECRET-TOKEN%' OR text_body LIKE '%SECRET-TOKEN%'"), 'Never stored unredacted.');
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail_body'));

        $delivered = $this->store($kernel)->sentVia('default')[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $delivered);
        foreach ([MailHeaders::LOG_ID, MailHeaders::TAG, MailHeaders::REDACT] as $header) {
            self::assertFalse($delivered->getHeaders()->has($header), 'Delivered without ' . $header);
            self::assertTrue($email->getHeaders()->has($header), 'The caller keeps ' . $header);
        }
    }

    private function boot(): MailKitTestKernel
    {
        return $this->bootKernel(
            ['mailer' => ['dsn_provider' => StaticDsnProvider::class, 'sender_provider' => StaticSenderProvider::class], 'log' => ['store_bodies' => true], 'web_ui' => ['enabled' => false]],
            without: ['instance_settings'],
            routes: static function (): void {
            },
            extra: static function (ContainerConfigurator $container): void {
                $container->services()->set(StaticDsnProvider::class)->args(['memory://fail'])->public();
                $container->services()->set(StaticSenderProvider::class);
            },
        );
    }
}
