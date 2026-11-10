<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Mailer;

use DateTimeImmutable;
use Nowo\MailKitBundle\Dsn\DsnHostGuard;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\Event\MailDeliveredEvent;
use Nowo\MailKitBundle\Event\MailDeliveryFailedEvent;
use Nowo\MailKitBundle\Mailer\DeliveryFailedException;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\MailKitBundle\Mailer\RuntimeTransport;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Tests\Support\FakeSettings;
use Nowo\MailKitBundle\Tests\Support\MemoryMailStore;
use Nowo\MailKitBundle\Tests\Support\MemoryTransport;
use Nowo\MailKitBundle\Tests\Support\MemoryTransportFactory;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use Nowo\MailKitBundle\Tests\Support\StaticDsnProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

use function array_filter;
use function array_values;
use function end;

/**
 * 1.5 (DDD P2, pattern 3): id-only delivery events after send / failure.
 */
final class RuntimeTransportEventsTest extends TestCase
{
    private MemoryMailStore $store;

    private EventDispatcher $dispatcher;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->store      = new MemoryMailStore();
        $this->dispatcher = new EventDispatcher();
        foreach ([MailDeliveredEvent::class, MailDeliveryFailedEvent::class] as $name) {
            $this->dispatcher->addListener($name, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    public function testDeliveredCarriesIdsAndLabelsOnly(): void
    {
        $email = $this->email();
        MailHeaders::tag($email, 'invoice');
        $email->getHeaders()->addTextHeader(MailHeaders::LOG_ID, 'log-1');

        $this->transport('memory://one')->send($email);

        self::assertCount(1, $this->events);
        $event = $this->events[0];
        self::assertInstanceOf(MailDeliveredEvent::class, $event);
        self::assertSame(['log-1', 'invoice', 'memory'], [$event->logId, $event->tag, $event->transport]);
        self::assertNotNull($event->providerMessageId);
        self::assertGreaterThan(0, $event->size);
        self::assertEquals(new DateTimeImmutable('2026-11-10 09:00:00'), $event->occurredAt);
        self::assertFalse($email->getHeaders()->has(MailHeaders::TAG), 'still stripped before delivery');
    }

    public function testFailedCarriesTheReference(): void
    {
        try {
            $this->transport('memory://fail')->send($this->email());
            self::fail('Expected the delivery failure.');
        } catch (DeliveryFailedException $failure) {
            self::assertCount(1, $this->events);
            $event = $this->events[0];
            self::assertInstanceOf(MailDeliveryFailedEvent::class, $event);
            self::assertSame([null, null, 'memory', $failure->reference], [$event->logId, $event->tag, $event->transport, $event->reference]);
        }
    }

    public function testAFailingListenerIsLoggedAndNeverChangesTheOutcome(): void
    {
        $logger = new RecordingLogger();
        $this->dispatcher->addListener(MailDeliveredEvent::class, static function (): never {
            throw new RuntimeException('listener down');
        });
        $this->dispatcher->addListener(MailDeliveryFailedEvent::class, static function (): never {
            throw new RuntimeException('listener down');
        });

        $this->transport('memory://one', $logger)->send($this->email());
        self::assertCount(1, $this->store->sentVia('one'), 'sent once, no exception');

        $this->expectException(DeliveryFailedException::class);
        try {
            $this->transport('memory://fail', $logger)->send($this->email());
        } finally {
            $errors = array_values(array_filter($logger->records, static fn (array $record): bool => $record['message'] === 'Mail delivery listener failed.'));
            self::assertCount(2, $errors);
            self::assertSame(['bundle' => 'NowoMailKitBundle', 'event' => MailDeliveredEvent::class, 'error' => RuntimeException::class], $errors[0]['context']);
        }
    }

    /**
     * Audit KI-M-L1: an alert mailed synchronously from a failure listener through the same failing transport does not
     * recurse; it is attempted once and dispatches no event of its own.
     */
    public function testASynchronousAlertFromAFailureListenerDoesNotRecurse(): void
    {
        $logger    = new RecordingLogger();
        $transport = $this->transport('memory://fail', $logger);
        $alerts    = 0;
        $this->dispatcher->addListener(MailDeliveryFailedEvent::class, function () use ($transport, &$alerts): void {
            ++$alerts;
            $transport->send($this->email()->subject('Alert'));
        });

        try {
            $transport->send($this->email());
            self::fail('Expected the delivery failure.');
        } catch (DeliveryFailedException) {
        }

        self::assertSame(1, $alerts, 'one level only');
        self::assertCount(1, $this->events, 'the alert dispatched no event');
        self::assertTrue($logger->has('Mail delivery event not dispatched'));
        self::assertTrue($logger->has('Mail delivery listener failed.'), 'the alert failure is logged, not thrown');

        // The guard is released: the next send dispatches again.
        $this->transport('memory://one')->send($this->email());
        $transport->reset();
        self::assertInstanceOf(MailDeliveredEvent::class, $this->lastEvent());
    }

    public function testWithoutADispatcherNothingIsDispatched(): void
    {
        $factory   = new Transport([new MemoryTransportFactory($this->store)]);
        $transport = new RuntimeTransport(
            new MemoryTransport('fallback', $this->store),
            new StaticDsnProvider(),
            new MailerDsnValidator(['memory'], new DsnHostGuard([], resolveDns: false), $factory),
            $factory,
            new SenderPolicy(new MailSettings(new FakeSettings([]))),
        );
        $transport->send(new RawMessage('raw'), new Envelope(new Address('a@example.com'), [new Address('b@example.com')]));

        self::assertSame([], $this->events);
        self::assertCount(1, $this->store->sentVia('fallback'));
    }

    private function transport(string $dsn, ?RecordingLogger $logger = null): RuntimeTransport
    {
        $factory = new Transport([new MemoryTransportFactory($this->store)]);

        return new RuntimeTransport(
            new MemoryTransport('fallback', $this->store),
            new StaticDsnProvider($dsn),
            new MailerDsnValidator(['memory'], new DsnHostGuard(['one', 'fail'], resolveDns: false), $factory),
            $factory,
            new SenderPolicy(new MailSettings(new FakeSettings(['mailer.from' => 'noreply@example.com']))),
            null,
            $logger,
            $this->dispatcher,
            new MockClock('2026-11-10 09:00:00'),
        );
    }

    private function lastEvent(): object|false
    {
        return end($this->events);
    }

    private function email(): Email
    {
        return (new Email())->to('a@example.com')->subject('S')->text('t');
    }
}
