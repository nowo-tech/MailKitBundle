<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound;

use DateInterval;
use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\GraphMailbox;
use Nowo\MailKitBundle\Graph\GraphMailboxProviderInterface;
use Nowo\MailKitBundle\Graph\Inbound\Message\FetchGraphMessage;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Messenger\MessageBusInterface;

use function is_array;
use function is_string;
use function parse_url;
use function preg_match;
use function rawurldecode;
use function rawurlencode;
use function str_starts_with;
use function strtolower;

use const PHP_URL_PATH;

/**
 * Delta sweep of a mailbox folder (1.9): catches messages whose change notification was missed, and is the only
 * reader on hosts without a public webhook URL. Follows `@odata.nextLink` / `@odata.deltaLink` **only** on
 * `https://graph.microsoft.com/v1.0/` ({@see GraphApiClient::assertGraphUrl()}), stores the link after every page (a
 * stopped run resumes there) and dispatches one {@see FetchGraphMessage} per new message (ids only; idempotency makes
 * a message seen by both paths harmless). The first sweep of a profile starts at `initial_window` before now, so an
 * existing inbox is not imported. An expired delta token (410) restarts from the window.
 */
final class GraphDeltaSweeper
{
    /**
     * @param string $initialWindow ISO 8601 duration of the first sweep (`P2D`)
     * @param int $pageSize messages per page (Prefer odata.maxpagesize)
     * @param string $folder well-known folder name (`inbox`)
     */
    public function __construct(
        private readonly GraphMailboxProviderInterface $mailboxes,
        private readonly GraphApiClient $api,
        private readonly GraphInboundStore $store,
        private readonly MessageBusInterface $bus,
        private readonly string $initialWindow = 'P2D',
        private readonly int $pageSize = 50,
        private readonly string $folder = 'inbox',
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Sweeps at most `$maxPages` pages of a profile.
     *
     * @throws GraphException
     *
     * @return array{pages: int, dispatched: int, complete: bool}
     */
    public function sweep(string $profile, int $maxPages = 20): array
    {
        $mailbox    = $this->mailboxes->mailbox($profile);
        $options    = ['headers' => ['Prefer' => 'odata.maxpagesize=' . $this->pageSize . ', IdType="ImmutableId"']];
        $link       = $this->store->deltaLink($profile);
        $pages      = 0;
        $dispatched = 0;

        try {
            if ($link === null) {
                $since = ($this->clock ?? new NativeClock())->now()->sub(new DateInterval($this->initialWindow))->format('Y-m-d\TH:i:s\Z');
                $data  = $this->api->call($mailbox, 'GET', GraphApiClient::userPath($mailbox) . '/mailFolders/' . rawurlencode($this->folder) . '/messages/delta?changeType=created&$select=id&$filter=' . rawurlencode('receivedDateTime ge ' . $since), $options, [200], 'delta sweep');
            } else {
                $this->assertMailboxLink($mailbox, $link);
                $data = $this->api->callUrl($mailbox, 'GET', $link, $options, [200], 'delta sweep');
            }

            while (true) {
                ++$pages;
                foreach (is_array($data['value'] ?? null) ? $data['value'] : [] as $item) {
                    if (is_array($item) && !isset($item['@removed']) && is_string($item['id'] ?? null) && preg_match(GraphInboundProcessor::ID_PATTERN, $item['id']) === 1) {
                        $this->bus->dispatch(new FetchGraphMessage($profile, $item['id']));
                        ++$dispatched;
                    }
                }
                $delta = $data['@odata.deltaLink'] ?? null;
                $next  = $data['@odata.nextLink'] ?? null;
                if (is_string($delta)) {
                    $this->assertMailboxLink($mailbox, $delta);
                    $this->store->saveDeltaLink($profile, $delta);

                    return $this->done($profile, $pages, $dispatched, true);
                }
                if (!is_string($next)) {
                    return $this->done($profile, $pages, $dispatched, false);
                }
                $this->assertMailboxLink($mailbox, $next);
                $this->store->saveDeltaLink($profile, $next);
                if ($pages >= $maxPages) {
                    return $this->done($profile, $pages, $dispatched, false);
                }
                $data = $this->api->callUrl($mailbox, 'GET', $next, $options, [200], 'delta sweep');
            }
        } catch (GraphException $exception) {
            if ($exception->status === 410) {
                // Delta token expired (syncStateNotFound / resyncRequired): restart from the initial window.
                $this->store->deleteDeltaLink($profile);
                $this->logger?->notice('Microsoft Graph delta token expired; the next sweep restarts from the initial window.', ['bundle' => 'NowoMailKitBundle', 'profile' => $profile]);

                return $this->done($profile, $pages, $dispatched, false);
            }

            throw $exception;
        }
    }

    /**
     * A link is followed only on Graph v1.0 **and** under this profile's mailbox (`/v1.0/users/{mailbox}/`, audit
     * NQ-I3), so a tampered stored link or a confused answer cannot read another mailbox with this token.
     *
     * @throws GraphException
     */
    private function assertMailboxLink(GraphMailbox $mailbox, string $url): void
    {
        $this->api->assertGraphUrl($url, 'delta sweep');
        $path = strtolower(rawurldecode((string) parse_url($url, PHP_URL_PATH)));
        if (!str_starts_with($path, '/v1.0/users/' . $mailbox->address . '/')) {
            throw GraphException::untrustedUrl('delta sweep');
        }
    }

    /**
     * @return array{pages: int, dispatched: int, complete: bool}
     */
    private function done(string $profile, int $pages, int $dispatched, bool $complete): array
    {
        $this->logger?->info('Microsoft Graph delta sweep finished.', ['bundle' => 'NowoMailKitBundle', 'profile' => $profile, 'pages' => $pages, 'dispatched' => $dispatched, 'complete' => $complete]);

        return ['pages' => $pages, 'dispatched' => $dispatched, 'complete' => $complete];
    }
}
