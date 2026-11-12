<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Tracking;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\Event\MailOpenedEvent;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\MailKitBundle\Tests\Support\RecordingBus;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use Nowo\MailKitBundle\Tests\Support\TrackingStoreTrait;
use Nowo\MailKitBundle\Tracking\Http\TrackingPixelController;
use Nowo\MailKitBundle\Tracking\Http\TrackingRouteLoader;
use Nowo\MailKitBundle\Tracking\Message\RecordMailOpen;
use Nowo\MailKitBundle\Tracking\Message\RecordMailOpenHandler;
use Nowo\MailKitBundle\Tracking\OpenClassifier;
use Nowo\MailKitBundle\Tracking\OpenRecorder;
use Nowo\MailKitBundle\Tracking\Store\TrackingSchemaListener;
use Nowo\MailKitBundle\Tracking\Store\TrackingStore;
use Nowo\MailKitBundle\Tracking\TrackingTokenSigner;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email;

final class TrackingHttpTest extends TestCase
{
    use TrackingStoreTrait;

    private const SECRET = 'test-tracking-secret-REDACTED-0000000000';

    public function testPixelCountsValidTokensAndSurvivesFailures(): void
    {
        $logger     = new RecordingLogger();
        $store      = $this->trackingStore();
        $dispatcher = new EventDispatcher();
        $events     = [];
        $dispatcher->addListener(MailOpenedEvent::class, static function (MailOpenedEvent $event) use (&$events): void {
            $events[] = $event;
        });
        $signer     = new TrackingTokenSigner(self::SECRET);
        $clock      = new MockClock('2026-11-12 10:00:00');
        $controller = new TrackingPixelController($signer, new OpenClassifier(), new OpenRecorder($store, $dispatcher, $logger), null, $clock, $logger);
        $id         = TrackingTokenSigner::newSendId();
        $store->createSend($id, null, null, null);

        $response = $controller(Request::create('/e/o/x.gif', server: ['HTTP_USER_AGENT' => 'Thunderbird/115', 'REMOTE_ADDR' => '203.0.113.5']), $signer->issue($id));
        self::assertSame('image/gif', $response->headers->get('Content-Type'));
        self::assertSame('43', $response->headers->get('Content-Length'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame(1, $store->find($id)->opens ?? null);
        self::assertSame($clock->now()->getTimestamp(), $events[0]->occurredAt->getTimestamp());
        self::assertSame('thunderbird', $store->find($id)->lastOpenFamily ?? null);

        // The store breaks: still the pixel, the failure is logged.
        $this->trackingDb()->executeStatement('DROP TABLE mail_kit_tracking_send');
        $broken = $controller(Request::create('/e/o/x.gif'), $signer->issue($id));
        self::assertSame(200, $broken->getStatusCode());
        self::assertTrue($logger->has('Mail open not recorded.'));
    }

    public function testBusRecorderListenerFailureAndHandler(): void
    {
        $store  = $this->trackingStore();
        $signer = new TrackingTokenSigner(self::SECRET);
        $bus    = new RecordingBus();
        $id     = TrackingTokenSigner::newSendId();
        $store->createSend($id, null, null, null);
        $recorder = new OpenRecorder($store);

        (new TrackingPixelController($signer, new OpenClassifier(), $recorder, $bus))(Request::create('/e/o/x.gif', server: ['REMOTE_ADDR' => '2001:db8:1:2::9', 'HTTP_USER_AGENT' => '']), $signer->issue($id));
        self::assertCount(1, $bus->messages);
        $message = $bus->messages[0];
        self::assertInstanceOf(RecordMailOpen::class, $message);
        self::assertSame([$id, true, '2001:db8:1::', 'unknown'], [$message->sendId, $message->machine, $message->ip, $message->family], 'No user agent: a machine.');
        self::assertSame(0, $store->find($id)->machineOpens ?? null, 'The bus only enqueues.');

        (new RecordMailOpenHandler($recorder))($message);
        self::assertSame(1, $store->find($id)->machineOpens ?? null);

        $logger     = new RecordingLogger();
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MailOpenedEvent::class, static function (): never {
            throw new RuntimeException('listener');
        });
        self::assertTrue((new OpenRecorder($store, $dispatcher, $logger))->record(new RecordMailOpen($id, false, null, null, 5)));
        self::assertTrue($logger->has('Mail open listener failed.'));
        self::assertFalse($recorder->record(new RecordMailOpen(TrackingTokenSigner::newSendId(), false, null, null, 5)));
    }

    public function testRouteLoaderAndSchemaListener(): void
    {
        $loader = new TrackingRouteLoader('/t/open');
        self::assertTrue($loader->supports('.', TrackingRouteLoader::TYPE));
        self::assertFalse($loader->supports('.', 'other'));
        $route = $loader->load('.')->get(TrackingRouteLoader::ROUTE);
        self::assertNotNull($route);
        self::assertSame('/t/open/{token}.gif', $route->getPath());
        self::assertSame(['GET', 'HEAD'], $route->getMethods());
        self::assertSame(TrackingPixelController::class, $route->getDefault('_controller'));
        $this->expectException(RuntimeException::class);
        $loader->load('.');
    }

    public function testSchemaListenerOnlyExtendsItsEntityManager(): void
    {
        $store = $this->trackingStore('app_');
        $mine  = $this->createStub(EntityManagerInterface::class);
        $mine->method('getConnection')->willReturn($this->trackingDb());
        $other    = $this->createStub(EntityManagerInterface::class);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($mine);
        $listener = new TrackingSchemaListener($store, $registry);

        $schema = new Schema();
        $listener->postGenerateSchema(new GenerateSchemaEventArgs($other, $schema));
        self::assertFalse($schema->hasTable('app_mail_kit_tracking_send'));
        $listener->postGenerateSchema(new GenerateSchemaEventArgs($mine, $schema));
        self::assertTrue($schema->hasTable('app_mail_kit_tracking_send'));
        $store->defineSchema($schema);
        self::assertCount(1, $schema->getTables());
    }

    public function testAStoreWriteSurvivesABrokenSavepoint(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('createSavepoint');
        $connection->method('insert')->willThrowException(new RuntimeException('insert'));
        $connection->method('rollbackSavepoint')->willThrowException(new RuntimeException('rollback'));
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($connection);
        $logger = new RecordingLogger();

        self::assertFalse((new TrackingStore($registry, null, '', null, $logger))->createSend(TrackingTokenSigner::newSendId(), null, null, null));
        self::assertTrue($logger->has('Mail tracking store write failed; the e-mail is sent untracked.'));
    }

    public function testInternalHeadersAreSetReplacedAndStripped(): void
    {
        $email = new Email();
        MailHeaders::template($email, '  issue_alert  ');
        MailHeaders::template($email, 'issue_alert_v2');
        MailHeaders::trackingRecipient($email, 'UserA', 'acme');
        MailHeaders::trackingRecipient($email, 'UserB');
        MailHeaders::noTracking($email);
        self::assertSame('issue_alert_v2', MailHeaders::value($email, MailHeaders::TEMPLATE));
        self::assertSame('UserB', MailHeaders::value($email, MailHeaders::RECIPIENT));
        self::assertNull(MailHeaders::value($email, MailHeaders::TENANT));
        self::assertSame('1', MailHeaders::value($email, MailHeaders::NO_TRACK));
        MailHeaders::strip($email);
        foreach ([MailHeaders::TEMPLATE, MailHeaders::RECIPIENT, MailHeaders::TENANT, MailHeaders::NO_TRACK] as $header) {
            self::assertFalse($email->getHeaders()->has($header));
        }
    }
}
