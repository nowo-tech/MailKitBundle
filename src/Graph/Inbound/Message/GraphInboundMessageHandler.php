<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound\Message;

use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\Inbound\GraphDeltaSweeper;
use Nowo\MailKitBundle\Graph\Inbound\GraphInboundProcessor;
use Nowo\MailKitBundle\Graph\Inbound\GraphInboundProfilesProviderInterface;
use Nowo\MailKitBundle\Graph\Inbound\GraphSubscriptionManager;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Messenger handler of the three inbound messages (1.9; registered with `messenger.message_handler` tags, one per
 * message class). Stateless.
 */
final class GraphInboundMessageHandler
{
    /**
     * @param int $maxPages pages of one sweep run
     * @param int $retentionDays idempotency records kept (purged after each sweep run)
     */
    public function __construct(
        private readonly GraphInboundProcessor $processor,
        private readonly GraphDeltaSweeper $sweeper,
        private readonly GraphSubscriptionManager $subscriptions,
        private readonly GraphInboundProfilesProviderInterface $profiles,
        private readonly GraphInboundStore $store,
        private readonly int $maxPages = 20,
        private readonly int $retentionDays = 30,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Fetches and processes one message.
     */
    public function fetch(FetchGraphMessage $message): void
    {
        $this->processor->process($message->profile, $message->graphMessageId);
    }

    /**
     * Sweeps one or every inbound profile, then purges old idempotency records.
     */
    public function sweep(SweepGraphMailbox $message): void
    {
        foreach ($this->targets($message->profile) as $profile) {
            $this->sweeper->sweep($profile, $this->maxPages);
        }
        $this->purge();
    }

    /**
     * Deletes idempotency records older than `retention_days` (after sweeps and renewals, audit NQ-I7).
     */
    private function purge(): void
    {
        $this->store->purge(($this->clock ?? new NativeClock())->now()->getTimestamp() - $this->retentionDays * 86_400);
    }

    /**
     * Creates / renews the subscriptions of one or every inbound profile (a failing profile does not stop the others).
     */
    public function renew(RenewGraphSubscriptions $message): void
    {
        $failure = null;
        foreach ($this->targets($message->profile) as $profile) {
            try {
                $this->subscriptions->ensure($profile, $message->reason);
            } catch (GraphException $exception) {
                $this->logger?->warning('Microsoft Graph subscription not renewed.', ['bundle' => 'NowoMailKitBundle', 'profile' => $profile, 'reason' => $exception->reason, 'status' => $exception->status]);
                $failure = $exception;
            }
        }
        $this->purge();
        if ($failure instanceof GraphException) {
            throw $failure;
        }
    }

    /**
     * @return list<string>
     */
    private function targets(?string $profile): array
    {
        return $profile !== null ? [$profile] : $this->profiles->inboundProfiles();
    }
}
