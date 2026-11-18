<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\EventListener;

use Nowo\MailKitBundle\Concurrency\StorageConflicts;
use Nowo\MailKitBundle\Controller\MailKitController;
use Nowo\MailKitBundle\EventListener\MailKitConflictListener;
use Nowo\MailKitBundle\Graph\Inbound\Webhook\GraphWebhookController;
use Nowo\MailKitBundle\Tests\Support\StorageFailures;
use Nowo\MailKitBundle\Tracking\Http\TrackingPixelController;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * REQ-RUNTIME-003 rule 6: the conflict answer of the bundle's routes (409 / 503, Retry-After, X-Conflict-Code).
 */
final class MailKitConflictListenerTest extends TestCase
{
    private function event(mixed $controller, Throwable $failure): ExceptionEvent
    {
        $request = Request::create('/x', 'POST');
        $request->attributes->set('_controller', $controller);

        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
    }

    public function testHostRoutesAndOtherFailuresAreLeftAlone(): void
    {
        $listener = new MailKitConflictListener();
        foreach ([null, ['App\\Controller', 'x'], 'App\\Controller\\HomeController::index'] as $controller) {
            $event = $this->event($controller, StorageFailures::deadlock());
            $listener($event);
            self::assertNull($event->getResponse());
            self::assertNotInstanceOf(HttpException::class, $event->getThrowable());
        }
        foreach ([StorageFailures::uniqueViolation(), StorageFailures::connectionLost(), new RuntimeException('x')] as $failure) {
            $event = $this->event(TrackingPixelController::class, $failure);
            $listener($event);
            self::assertNull($event->getResponse());
            self::assertSame($failure, $event->getThrowable());
        }
    }

    public function testPixelAndWebhookGetAPlainConflictAnswer(): void
    {
        $listener = new MailKitConflictListener();
        $event    = $this->event(TrackingPixelController::class, StorageFailures::lockWaitTimeout());
        $listener($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame(MailKitConflictListener::MESSAGE, $response->getContent());

        $event = $this->event(GraphWebhookController::class, StorageConflicts::committed(StorageFailures::deadlock()));
        $listener($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_002', $response->headers->get('X-Conflict-Code'));
        self::assertFalse($response->headers->has('Retry-After'));
        self::assertSame(MailKitConflictListener::COMMITTED_MESSAGE, $response->getContent());
    }

    public function testStatusAndRetryAfterAreConfigurable(): void
    {
        $listener = new MailKitConflictListener(new ConflictResponsePolicy(503, 5));
        $event    = $this->event(TrackingPixelController::class, StorageFailures::deadlock());
        $listener($event);
        self::assertSame(503, $event->getResponse()?->getStatusCode());
        self::assertSame('5', $event->getResponse()->headers->get('Retry-After'));

        $event = $this->event(TrackingPixelController::class, new TransientStorageFailure(9));
        $listener($event);
        self::assertSame('9', $event->getResponse()?->headers->get('Retry-After'), 'The runner hint wins.');
    }

    public function testAdminRoutesBecomeAnHttpExceptionForTheHostErrorPage(): void
    {
        $listener = new MailKitConflictListener();
        $failure  = StorageFailures::deadlock();
        $event    = $this->event(MailKitController::class . '::sendTest', $failure);
        $listener($event);
        self::assertNull($event->getResponse());
        $exception = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $exception);
        self::assertSame(409, $exception->getStatusCode());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '1'], $exception->getHeaders());
        self::assertSame(MailKitConflictListener::MESSAGE, $exception->getMessage());
        self::assertSame($failure, $exception->getPrevious());

        $event = $this->event(MailKitController::class . '::sendTest', StorageFailures::lostSavepoint());
        $listener($event);
        $exception = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $exception);
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_002'], $exception->getHeaders());
        self::assertSame(MailKitConflictListener::COMMITTED_MESSAGE, $exception->getMessage());
    }
}
