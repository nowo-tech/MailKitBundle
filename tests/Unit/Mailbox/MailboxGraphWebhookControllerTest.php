<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Mailbox;

use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\FetchMailboxMessage;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\RenewMailboxSubscriptions;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\SyncMailboxFolder;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Webhook\MailboxGraphWebhookController;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Tests\Support\MailboxHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

use function json_encode;

/**
 * 2.5.0 delegated-mailbox webhook: the 1.9 HTTP flow with MailKit's own subscription rows; the mailbox and folder come
 * from the row, never from the payload; a paused mailbox is acknowledged without work; lifecycle events enqueue the
 * renewal or a sweep.
 */
final class MailboxGraphWebhookControllerTest extends TestCase
{
    use MailboxHarness;

    private MailboxGraphWebhookController $controller;

    protected function setUp(): void
    {
        $this->harness();
        $id = new MailboxId('mbx-ana');
        $this->mailboxStore->insertMailbox($id, MailboxProvider::Graph);
        $this->mailboxStore->saveSubscription('sub-1', $id, 'inbox', 'state-REDACTED-TEST', 2_000_000_000);
        $limiter          = static fn (string $name, int $limit): RateLimiterFactory => new RateLimiterFactory(['id' => $name, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'], new InMemoryStorage());
        $this->controller = new MailboxGraphWebhookController($this->mailboxStore, $this->bus, $limiter('v', 100), $limiter('c', 100), logger: $this->log);
    }

    public function testValidNotificationsEnqueueWorkFromTheStoredRow(): void
    {
        $response = ($this->controller)($this->request([
            ['subscriptionId' => 'sub-1', 'clientState' => 'state-REDACTED-TEST', 'changeType' => 'created', 'resource' => "users/other@example.org/messages('x')", 'resourceData' => ['id' => 'm1']],
            ['subscriptionId' => 'sub-1', 'clientState' => 'state-REDACTED-TEST', 'changeType' => 'created', 'resourceData' => ['id' => 'm1']],
            ['subscriptionId' => 'sub-1', 'clientState' => 'state-REDACTED-TEST', 'changeType' => 'created', 'resourceData' => ['id' => 'bad id']],
            ['subscriptionId' => 'sub-1', 'clientState' => 'state-REDACTED-TEST', 'lifecycleEvent' => 'missed'],
            ['subscriptionId' => 'sub-1', 'clientState' => 'state-REDACTED-TEST', 'lifecycleEvent' => 'reauthorizationRequired'],
            ['subscriptionId' => 'sub-1', 'clientState' => 'state-REDACTED-TEST', 'lifecycleEvent' => 'subscriptionRemoved'],
            ['subscriptionId' => 'sub-1', 'clientState' => 'state-REDACTED-TEST', 'lifecycleEvent' => 'somethingNew'],
            ['subscriptionId' => 'sub-1', 'clientState' => 'forged', 'changeType' => 'created', 'resourceData' => ['id' => 'm9']],
        ]));

        self::assertSame(202, $response->getStatusCode());
        self::assertEquals([
            new FetchMailboxMessage('mbx-ana', 'inbox', 'm1'),
            new SyncMailboxFolder('mbx-ana', 'inbox'),
            new RenewMailboxSubscriptions('mbx-ana', RenewMailboxSubscriptions::REAUTHORIZE, 'sub-1'),
            new RenewMailboxSubscriptions('mbx-ana', RenewMailboxSubscriptions::REMOVED, 'sub-1'),
        ], $this->bus->messages);
        self::assertTrue($this->log->has('lifecycle event ignored'));
    }

    public function testForgedAndPausedNotifications(): void
    {
        self::assertSame(403, ($this->controller)($this->request([['subscriptionId' => 'sub-1', 'clientState' => 'forged', 'changeType' => 'created', 'resourceData' => ['id' => 'm1']]]))->getStatusCode());
        self::assertSame(403, ($this->controller)($this->request([['subscriptionId' => 'unknown', 'clientState' => 'state-REDACTED-TEST']]))->getStatusCode());

        $this->mailboxStore->pauseByHost(new MailboxId('mbx-ana'));
        self::assertSame(202, ($this->controller)($this->request([['subscriptionId' => 'sub-1', 'clientState' => 'state-REDACTED-TEST', 'changeType' => 'created', 'resourceData' => ['id' => 'm1']]]))->getStatusCode());
        self::assertSame([], $this->bus->messages);

        $echo = ($this->controller)(Request::create('/hooks/mail-kit/v1/mailbox/graph?validationToken=abc%20123', 'POST'));
        self::assertSame([200, 'abc 123', 'text/plain; charset=utf-8'], [$echo->getStatusCode(), $echo->getContent(), $echo->headers->get('Content-Type')]);
    }

    /**
     * @param list<mixed> $items
     */
    private function request(array $items): Request
    {
        return Request::create('/hooks/mail-kit/v1/mailbox/graph', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['value' => $items]));
    }
}
