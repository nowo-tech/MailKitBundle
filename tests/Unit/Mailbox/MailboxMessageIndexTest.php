<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Mailbox;

use Closure;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\FetchMailboxMessage;
use Nowo\MailKitBundle\Mailbox\Port\MailboxMessageIndexInterface;
use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Tests\Support\MailboxGraphFake;
use Nowo\MailKitBundle\Tests\Support\MailboxHarness;
use Nowo\MailKitBundle\Tests\Support\MemoryMailboxPorts;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;

/**
 * 2.5.0 part 2, owner answer Q1 (2026-11-24): the optional message index skips the fetch of messages the host already
 * stores in the folder; the page is asked once, before its fetches are enqueued; a failing index fetches everything.
 */
final class MailboxMessageIndexTest extends TestCase
{
    use MailboxHarness;

    /** @var list<array{mailbox: string, folder: string, ids: list<string>}> */
    private array $asked = [];

    /** @var Closure(list<ProviderMessageId>): list<ProviderMessageId> */
    private Closure $answer;

    protected function setUp(): void
    {
        $this->answer = static fn (array $ids): array => [];
        $index        = new class($this) implements MailboxMessageIndexInterface {
            public function __construct(private readonly MailboxMessageIndexTest $test)
            {
            }

            public function contains(MailboxId $mailbox, FolderRef $folder, array $messages): array
            {
                return $this->test->contains($mailbox, $folder, $messages);
            }
        };
        $this->harness(index: $index);
        $this->ports->add(MemoryMailboxPorts::settings('mbx-ana', 'ana@example.com', ['inbox']));
        foreach (['m1', 'm2', 'm3'] as $id) {
            $this->graph->messages[$id] = MailboxGraphFake::message($id);
        }
        $this->graph->delta['inbox'] = [[['id' => 'm1'], ['id' => 'm2'], ['id' => 'm0', '@removed' => ['reason' => 'deleted']]], [['id' => 'm3']]];
    }

    /**
     * @param list<ProviderMessageId> $messages
     *
     * @return list<ProviderMessageId>
     */
    public function contains(MailboxId $mailbox, FolderRef $folder, array $messages): array
    {
        $this->asked[] = ['mailbox' => $mailbox->value, 'folder' => $folder->key, 'ids' => array_map(static fn (ProviderMessageId $id): string => $id->value, $messages)];

        return ($this->answer)($messages);
    }

    public function testKnownMessagesOfAPageAreNotFetchedAgain(): void
    {
        $this->answer = static fn (array $ids): array => [new ProviderMessageId('m2'), new ProviderMessageId('m9-not-asked')];
        $this->engine->start(new MailboxId('mbx-ana'));
        self::assertSame([], $this->drain());

        self::assertSame([
            ['mailbox' => 'mbx-ana', 'folder' => 'inbox', 'ids' => ['m1', 'm2']],
            ['mailbox' => 'mbx-ana', 'folder' => 'inbox', 'ids' => ['m3']],
        ], $this->asked, 'once per page, removals left out');
        self::assertSame(['mbx-ana' . "\n" . 'm1', 'mbx-ana' . "\n" . 'm3'], array_keys($this->ports->stored));
        self::assertSame([], $this->graph->matching('/me/messages/m2'));
        self::assertCount(1, $this->ports->removedMessages);
    }

    public function testAFailingIndexFetchesEveryMessage(): void
    {
        $this->answer = static fn (array $ids): never => throw new RuntimeException('index down');
        $this->engine->start(new MailboxId('mbx-ana'));
        self::assertSame([], $this->drain());
        self::assertCount(3, array_values(array_filter($this->bus->messages, static fn (object $m): bool => $m instanceof FetchMailboxMessage)) + $this->ports->stored);
        self::assertCount(3, $this->ports->stored);
        self::assertTrue($this->log->has('Mailbox message index failed; every message of the page is fetched.'));
    }

    public function testAPageWithRemovalsOnlyAsksNothing(): void
    {
        $this->graph->delta['inbox'] = [[['id' => 'm0', '@removed' => ['reason' => 'deleted']]]];
        $this->engine->start(new MailboxId('mbx-ana'));
        self::assertSame([], $this->drain());
        self::assertSame([], $this->asked);
    }
}
