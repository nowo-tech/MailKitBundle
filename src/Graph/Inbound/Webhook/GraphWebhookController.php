<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound\Webhook;

use JsonException;
use Nowo\MailKitBundle\Graph\Inbound\GraphInboundProcessor;
use Nowo\MailKitBundle\Graph\Inbound\Message\FetchGraphMessage;
use Nowo\MailKitBundle\Graph\Inbound\Message\RenewGraphSubscriptions;
use Nowo\MailKitBundle\Graph\Inbound\Message\SweepGraphMailbox;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

use function count;
use function fread;
use function is_array;
use function is_resource;
use function is_string;
use function json_decode;
use function max;
use function preg_match;
use function str_starts_with;
use function strlen;
use function strtolower;

use const JSON_THROW_ON_ERROR;

/**
 * Microsoft Graph change-notification endpoint (1.9; route from the `nowo_mail_kit_graph_webhook` loader, POST only).
 * Public by design (Graph sends no credentials): every notification is checked against a stored subscription and its
 * `clientState` (SHA-256 digests compared in constant time), and the endpoint only **enqueues** work — one
 * {@see FetchGraphMessage} per new message, a renewal or a sweep for lifecycle events — so it answers well within
 * Graph's 3 s budget. No session, no CSRF token (no form), no cookie is read.
 *
 * - `?validationToken=…` (subscription validation): echoed as `text/plain` when it is 1–1024 printable ASCII
 *   characters (`nosniff`, `default-src 'none'`), else 400.
 * - Notifications: `application/json`, at most `max_body_bytes` and `max_notifications`, else 413 / 415 / 400;
 *   202 when at least one notification is valid, 403 when none is. A global sliding-window rate limit answers 429.
 */
final class GraphWebhookController
{
    /** Longest validation token echoed. */
    public const MAX_VALIDATION_TOKEN = 1024;

    /**
     * @param int $maxBodyBytes largest request body read
     * @param int $maxNotifications most notifications per request
     */
    public function __construct(
        private readonly GraphInboundStore $store,
        private readonly MessageBusInterface $bus,
        private readonly RateLimiterFactory $limiter,
        private readonly int $maxBodyBytes = 1_048_576,
        private readonly int $maxNotifications = 200,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Handles a validation request or a notification batch.
     */
    public function __invoke(Request $request): Response
    {
        if (!$this->limiter->create('graph-webhook')->consume()->isAccepted()) {
            return $this->plain('', 429);
        }
        if ($request->query->has('validationToken')) {
            $token = $request->query->all()['validationToken'] ?? null;
            if (!is_string($token) || strlen($token) > self::MAX_VALIDATION_TOKEN || preg_match('/^[\x20-\x7E]+\z/', $token) !== 1) {
                return $this->plain('', 400);
            }

            return $this->plain($token, 200);
        }

        if (!str_starts_with(strtolower((string) $request->headers->get('Content-Type')), 'application/json')) {
            return $this->plain('', 415);
        }
        $length = $request->headers->get('Content-Length');
        if ($length !== null && (int) $length > $this->maxBodyBytes) {
            return $this->plain('', 413);
        }
        $handle = $request->getContent(true);
        $body   = is_resource($handle) ? (string) fread($handle, max(1, $this->maxBodyBytes + 1)) : '';
        if (strlen($body) > $this->maxBodyBytes) {
            return $this->plain('', 413);
        }

        try {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->plain('', 400);
        }
        $items = is_array($payload) && is_array($payload['value'] ?? null) ? $payload['value'] : null;
        if ($items === null || $items === []) {
            return $this->plain('', 400);
        }
        if (count($items) > $this->maxNotifications) {
            return $this->plain('', 413);
        }

        $accepted = 0;
        $rejected = 0;
        $seen     = [];
        foreach ($items as $item) {
            $profile = $this->verify($item);
            if ($profile === null) {
                ++$rejected;

                continue;
            }
            /* @var array<string, mixed> $item */
            ++$accepted;
            $lifecycle = is_string($item['lifecycleEvent'] ?? null) ? $item['lifecycleEvent'] : null;
            if ($lifecycle !== null) {
                $this->bus->dispatch($lifecycle === 'missed' ? new SweepGraphMailbox($profile) : new RenewGraphSubscriptions($profile));

                continue;
            }
            $resource = is_array($item['resourceData'] ?? null) ? $item['resourceData'] : [];
            $id       = is_string($resource['id'] ?? null) ? $resource['id'] : '';
            if (($item['changeType'] ?? null) === 'created' && preg_match(GraphInboundProcessor::ID_PATTERN, $id) === 1 && !isset($seen[$profile . "\n" . $id])) {
                $seen[$profile . "\n" . $id] = true;
                $this->bus->dispatch(new FetchGraphMessage($profile, $id));
            }
        }
        $this->logger?->info('Microsoft Graph notifications received.', ['bundle' => 'NowoMailKitBundle', 'accepted' => $accepted, 'rejected' => $rejected, 'queued' => count($seen)]);

        return $this->plain('', $accepted > 0 ? 202 : 403);
    }

    /**
     * The profile of a notification with a known subscription and the right client state, else null.
     */
    private function verify(mixed $item): ?string
    {
        if (!is_array($item)) {
            return null;
        }
        $subscription = $item['subscriptionId'] ?? null;
        $clientState  = $item['clientState'] ?? null;
        if (!is_string($subscription) || !is_string($clientState) || preg_match('/^[A-Za-z0-9-]{1,128}\z/', $subscription) !== 1 || strlen($clientState) > 255) {
            return null;
        }

        return $this->store->verifyNotification($subscription, $clientState);
    }

    private function plain(string $content, int $status): Response
    {
        return new Response($content, $status, [
            'Content-Type'            => 'text/plain; charset=utf-8',
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            'Cache-Control'           => 'no-store',
        ]);
    }
}
