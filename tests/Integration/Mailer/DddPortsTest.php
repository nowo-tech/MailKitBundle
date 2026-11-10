<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Mailer;

use Nowo\MailKitBundle\Event\MailDeliveredEvent;
use Nowo\MailKitBundle\Event\MailDeliveryFailedEvent;
use Nowo\MailKitBundle\Mailer\DeliveryFailedException;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Support\StaticSenderProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mime\Email;

/**
 * 1.5 (DDD P2) in a real kernel: a host sender provider instead of the settings, and the id-only delivery events
 * carrying the sent-mail log row id.
 */
final class DddPortsTest extends KernelTestCase
{
    public function testAHostSenderProviderAndDeliveryEvents(): void
    {
        $kernel = $this->bootKernel(['mailer' => ['sender_provider' => StaticSenderProvider::class]], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set(StaticSenderProvider::class);
        });
        $this->settings($kernel)->save('mailer', ['dsn' => 'memory://default', 'from' => 'settings@example.com']);
        /** @var list<object> $events */
        $events     = [];
        $dispatcher = $this->container($kernel)->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach ([MailDeliveredEvent::class, MailDeliveryFailedEvent::class] as $name) {
            $dispatcher->addListener($name, static function (object $event) use (&$events): void {
                $events[] = $event;
            });
        }

        $email = (new Email())->to('a@example.com')->subject('Invite')->text('Hi');
        MailHeaders::tag($email, 'invitation');
        $this->mailer($kernel)->send($email);

        $sent = $this->store($kernel)->sentVia('default')[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $sent);
        self::assertSame('"Tenant" <tenant@example.com>', $sent->getFrom()[0]->toString());
        self::assertSame('tenant-help@example.com', $sent->getReplyTo()[0]->getAddress());

        self::assertCount(1, $events);
        $delivered = $events[0];
        self::assertInstanceOf(MailDeliveredEvent::class, $delivered);
        self::assertSame(['invitation', 'memory'], [$delivered->tag, $delivered->transport]);
        self::assertNotNull($delivered->logId);
        self::assertSame('sent', $this->connection($kernel)->fetchOne('SELECT status FROM mail_kit_sent_mail WHERE id = ?', [$delivered->logId]));

        $this->settings($kernel)->save('mailer', ['dsn' => 'memory://fail']);
        try {
            $this->mailer($kernel)->send((new Email())->to('a@example.com')->subject('Fails')->text('Hi'));
            self::fail('Expected the delivery failure.');
        } catch (DeliveryFailedException $failure) {
            $failed = $this->second($events);
            self::assertInstanceOf(MailDeliveryFailedEvent::class, $failed);
            self::assertSame($failure->reference, $failed->reference);
            self::assertSame('failed', $this->connection($kernel)->fetchOne('SELECT status FROM mail_kit_sent_mail WHERE id = ?', [$failed->logId]));
        }
    }

    /**
     * @param list<object> $events
     */
    private function second(array $events): ?object
    {
        return $events[1] ?? null;
    }
}
