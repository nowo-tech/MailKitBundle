<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound\Webhook;

use JsonException;
use Nowo\MailKitBundle\Graph\Inbound\GraphInboundProcessor;
use Nowo\MailKitBundle\Graph\Inbound\GraphInboundProfilesProviderInterface;
use Nowo\MailKitBundle\Graph\Inbound\Message\FetchGraphMessage;
use Nowo\MailKitBundle\Graph\Inbound\Message\RenewGraphSubscriptions;
use Nowo\MailKitBundle\Graph\Inbound\Message\SweepGraphMailbox;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

use function array_key_exists;
use function count;
use function hash;
use function in_array;
use function is_array;
use function is_resource;
use function is_string;
use function json_decode;
use function max;
use function preg_match;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function strtolower;

use const JSON_THROW_ON_ERROR;

/**
 * Microsoft Graph change-notification endpoint (1.9; route from the `nowo_mail_kit_graph_webhook` loader, POST only).
 * Public by design (Graph sends no credentials): every notification is checked against a stored, unexpired
 * subscription of an inbound profile and its `clientState` (SHA-256 digests compared in constant time), and the
 * endpoint only **enqueues** work — one {@see FetchGraphMessage} per new message, a renewal or a sweep for lifecycle
 * events — so it answers well within Graph's 3 s budget. No session, no CSRF token (no form), no cookie is read.
 *
 * - Optional source allow-list (`webhook.allowed_ips`, CIDR): anything else is 403 before any work.
 * - Unverified traffic (validation requests, malformed or rejected batches) consumes a **per-client-IP** bucket; a
 *   request with at least one valid notification consumes the **verified** bucket instead, so a flood of forged
 *   requests cannot starve Graph's own notifications (audit NQ-M2). An exhausted bucket answers 429.
 * - `?validationToken=…`: echoed as `text/plain` when it is 1–1024 printable ASCII characters (`nosniff`,
 *   `default-src 'none'`), else 400.
 * - Notifications: `application/json`, at most `max_body_bytes` (read to the cap, whatever the stream's chunk size) and
 *   `max_notifications`, else 413 / 415 / 400; each subscription is verified once per request; after
 *   {@see MAX_REJECTED} distinct rejected subscriptions no further one is looked up. 202 when at least one
 *   notification is valid (stale or forged items never cause a 403 next to valid ones), else 403.
 */
final class GraphWebhookController
{
    /** Longest validation token echoed. */
    public const MAX_VALIDATION_TOKEN = 1024;

    /** Distinct rejected subscriptions after which no further one is looked up in a request. */
    public const MAX_REJECTED = 5;

    /**
     * @param RateLimiterFactory $verifiedLimiter global bucket of requests with a valid notification
     * @param RateLimiterFactory $clientLimiter per-client-IP bucket of unverified requests
     * @param int $maxBodyBytes largest request body read
     * @param int $maxNotifications most notifications per request
     * @param list<string> $allowedIps accepted source addresses / CIDR ranges (empty = any)
     */
    public function __construct(
        private readonly GraphInboundStore $store,
        private readonly MessageBusInterface $bus,
        private readonly RateLimiterFactory $verifiedLimiter,
        private readonly RateLimiterFactory $clientLimiter,
        private readonly GraphInboundProfilesProviderInterface $profiles,
        private readonly int $maxBodyBytes = 1_048_576,
        private readonly int $maxNotifications = 200,
        private readonly array $allowedIps = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Handles a validation request or a notification batch.
     */
    public function __invoke(Request $request): Response
    {
        $ip = (string) $request->getClientIp();
        if ($this->allowedIps !== [] && !IpUtils::checkIp($ip, $this->allowedIps)) {
            return $this->plain('', 403);
        }
        // IPv6 clients are keyed by their /64 (one host usually owns the whole prefix).
        $client    = $this->clientLimiter->create('graph-webhook-ip-' . hash('sha256', IpUtils::anonymize($ip, 0, 8)));
        $exhausted = $client->consume(0)->getRemainingTokens() <= 0;
        if ($exhausted && $request->query->has('validationToken')) {
            return $this->plain('', 429);
        }

        // Graph shares Microsoft's egress addresses with every other tenant (audit NQ-v M1): an exhausted client bucket
        // only narrows the request to a cheap path (the first unknown subscription ends it); a valid notification
        // still goes through.
        [$status, $content, $valid, $anyValid] = $this->handle($request, $exhausted ? 1 : self::MAX_REJECTED);
        if (!$anyValid) {
            if ($exhausted) {
                return $this->plain('', 429);
            }
            $client->consume();

            return $this->plain($content, $status);
        }
        if (!$this->verifiedLimiter->create('graph-webhook-verified')->consume()->isAccepted()) {
            return $this->plain('', 429);
        }
        $queued = 0;
        foreach ($valid as $message) {
            $this->bus->dispatch($message);
            $queued += $message instanceof FetchGraphMessage ? 1 : 0;
        }
        $this->logger?->info('Microsoft Graph notifications received.', ['bundle' => 'NowoMailKitBundle', 'accepted' => count($valid), 'queued' => $queued]);

        return $this->plain('', 202);
    }

    /**
     * @param int $maxRejected distinct rejected subscriptions after which no further one is looked up
     *
     * @return array{0: int, 1: string, 2: list<object>, 3: bool} status and content of an unverified answer, messages to
     *                                                            dispatch, whether at least one notification was valid
     */
    private function handle(Request $request, int $maxRejected): array
    {
        if ($request->query->has('validationToken')) {
            $token = $request->query->all()['validationToken'] ?? null;
            if (!is_string($token) || strlen($token) > self::MAX_VALIDATION_TOKEN || preg_match('/^[\x20-\x7E]+\z/', $token) !== 1) {
                return [400, '', [], false];
            }

            return [200, $token, [], false];
        }
        if (!str_starts_with(strtolower((string) $request->headers->get('Content-Type')), 'application/json')) {
            return [415, '', [], false];
        }
        $length = $request->headers->get('Content-Length');
        if ($length !== null && (int) $length > $this->maxBodyBytes) {
            return [413, '', [], false];
        }
        $handle = $request->getContent(true);
        // Read to the cap: php://input hands out 8 KiB per fread() (audit NQ-M1).
        $body = is_resource($handle) ? (string) stream_get_contents($handle, max(1, $this->maxBodyBytes + 1)) : '';
        if (strlen($body) > $this->maxBodyBytes) {
            return [413, '', [], false];
        }

        try {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [400, '', [], false];
        }
        $items = is_array($payload) && is_array($payload['value'] ?? null) ? $payload['value'] : null;
        if ($items === null || $items === []) {
            return [400, '', [], false];
        }
        if (count($items) > $this->maxNotifications) {
            return [413, '', [], false];
        }

        $profiles = $this->profiles->inboundProfiles();
        $verified = [];
        $messages = [];
        $seen     = [];
        $rejected = [];
        $anyValid = false;
        foreach ($items as $item) {
            $profile = $this->verify($item, $verified, $profiles, count($rejected) < $maxRejected);
            if ($profile === null) {
                $rejected[is_array($item) && is_string($item['subscriptionId'] ?? null) ? $item['subscriptionId'] : '?'] = true;

                continue;
            }
            /** @var array<string, mixed> $item */
            $anyValid  = true;
            $lifecycle = is_string($item['lifecycleEvent'] ?? null) ? $item['lifecycleEvent'] : null;
            if ($lifecycle !== null) {
                $subscription = (string) $item['subscriptionId'];
                $message      = match ($lifecycle) {
                    'missed'                  => new SweepGraphMailbox($profile),
                    'reauthorizationRequired' => new RenewGraphSubscriptions($profile, RenewGraphSubscriptions::REAUTHORIZE, $subscription),
                    'subscriptionRemoved'     => new RenewGraphSubscriptions($profile, RenewGraphSubscriptions::REMOVED, $subscription),
                    default                   => null,
                };
                if ($message === null) {
                    $this->logger?->notice('Microsoft Graph lifecycle event ignored (unknown).', ['bundle' => 'NowoMailKitBundle', 'profile' => $profile]);

                    continue;
                }
                $messages[] = $message;

                continue;
            }
            $resource = is_array($item['resourceData'] ?? null) ? $item['resourceData'] : [];
            $id       = is_string($resource['id'] ?? null) ? $resource['id'] : '';
            if (($item['changeType'] ?? null) === 'created' && preg_match(GraphInboundProcessor::ID_PATTERN, $id) === 1 && !isset($seen[$profile . "\n" . $id])) {
                $seen[$profile . "\n" . $id] = true;
                $messages[]                  = new FetchGraphMessage($profile, $id);
            }
        }
        if ($rejected !== []) {
            $this->logger?->notice('Microsoft Graph notifications rejected.', ['bundle' => 'NowoMailKitBundle', 'subscriptions' => count($rejected)]);
        }

        // A request with valid items is never refused for its stale or forged ones (202 for the valid ones).
        return $anyValid ? [202, '', $messages, true] : [403, '', [], false];
    }

    /**
     * The profile of a notification with a known, unexpired subscription of an inbound profile and the right client
     * state, else null; one store look-up per subscription id and client state in a request.
     *
     * @param array<string, string|null> $verified memo of this request
     * @param list<string> $profiles inbound profiles
     * @param bool $lookup false once too many subscriptions were rejected: only already verified ones pass
     */
    private function verify(mixed $item, array &$verified, array $profiles, bool $lookup): ?string
    {
        if (!is_array($item)) {
            return null;
        }
        $subscription = $item['subscriptionId'] ?? null;
        $clientState  = $item['clientState'] ?? null;
        if (!is_string($subscription) || !is_string($clientState) || preg_match('/^[A-Za-z0-9-]{1,128}\z/', $subscription) !== 1 || strlen($clientState) > 255) {
            return null;
        }
        $key = $subscription . "\n" . hash('sha256', $clientState);
        if (!array_key_exists($key, $verified)) {
            if (!$lookup) {
                return null;
            }
            $profile        = $this->store->verifyNotification($subscription, $clientState);
            $verified[$key] = $profile !== null && in_array($profile, $profiles, true) ? $profile : null;
        }

        return $verified[$key];
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
