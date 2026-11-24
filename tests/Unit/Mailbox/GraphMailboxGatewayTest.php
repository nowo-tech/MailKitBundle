<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Mailbox;

use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\Inbound\GraphMessageNormalizer;
use Nowo\MailKitBundle\Mailbox\Engine\ChangePage;
use Nowo\MailKitBundle\Mailbox\Engine\CursorExpired;
use Nowo\MailKitBundle\Mailbox\Engine\FetchedMessage;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSession;
use Nowo\MailKitBundle\Mailbox\Engine\ProviderAccount;
use Nowo\MailKitBundle\Mailbox\Engine\RemoteSubscription;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Graph\GraphMailboxGateway;
use Nowo\MailKitBundle\Mailbox\Html\MailHtmlSanitizer;
use Nowo\MailKitBundle\Mailbox\Html\RemoteImages;
use Nowo\MailKitBundle\Mailbox\Value\AttachmentKind;
use Nowo\MailKitBundle\Mailbox\Value\AttachmentRef;
use Nowo\MailKitBundle\Mailbox\Value\FetchedAttachment;
use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\MessageOrigin;
use Nowo\MailKitBundle\Mailbox\Value\ProviderAttachmentId;
use Nowo\MailKitBundle\Mailbox\Value\ProviderCall;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Sender\MailAddress;
use Nowo\MailKitBundle\Tests\Support\MailboxGraphFake;
use Nowo\MailKitBundle\Tests\Support\MemoryMailboxPorts;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_map;
use function array_slice;
use function count;
use function fread;
use function hash;
use function range;
use function str_contains;
use function str_repeat;
use function stream_get_contents;
use function substr;

/**
 * 2.5.0 Graph delegated gateway against a fake Graph: `/me` paths only with the host's token, validated and bounded
 * fields, the delta link guard, the grant-failure classification (design section 13), oversized messages, attachment
 * references and on-demand reads, and per-call metering.
 */
final class GraphMailboxGatewayTest extends TestCase
{
    private MailboxGraphFake $graph;

    private MemoryMailboxPorts $ports;

    private GraphMailboxGateway $gateway;

    protected function setUp(): void
    {
        $this->graph   = new MailboxGraphFake();
        $this->ports   = new MemoryMailboxPorts();
        $this->gateway = new GraphMailboxGateway(
            new GraphApiClient($this->graph->client(), null),
            new GraphMessageNormalizer(new MailHtmlSanitizer(RemoteImages::Blocked)),
            65_536,
        );
    }

    public function testAccountUsesOnlyMeWithTheHostToken(): void
    {
        self::assertSame(MailboxProvider::Graph, $this->gateway->provider());
        $this->graph->account = ['id' => 'ABC-1', 'mail' => 'Ana@Example.com', 'userPrincipalName' => 'ANA@example.com'];
        $account              = $this->gateway->account($this->session());

        self::assertSame('abc-1', $account->id);
        self::assertSame(['ana@example.com'], $account->addresses);
        self::assertSame('Bearer fake-mbx-ana-1', $this->graph->requests[0]['authorization']);
        self::assertSame('/v1.0/me', $this->graph->requests[0]['path']);
        self::assertSame(['account'], array_map(static fn (ProviderCall $c): string => $c->operation, $this->ports->calls));
        self::assertSame(200, $this->ports->calls[0]->status);

        $this->graph->account = ['id' => 'bad id', 'mail' => null];
        $this->expectFailure(MailboxProviderFailure::REFUSED, fn (): ProviderAccount => $this->gateway->account($this->session()));
    }

    public function testA401IsRetriedOnceWithAFreshTokenThenRevokes(): void
    {
        $first                 = true;
        $this->graph->override = static function (string $method, string $path) use (&$first): ?MockResponse {
            if ($path === '/v1.0/me?$select=id,mail,userPrincipalName' && $first) {
                $first = false;

                return MailboxGraphFake::json(['error' => ['code' => 'InvalidAuthenticationToken']], 401);
            }

            return null;
        };
        $session = $this->session();
        $this->gateway->account($session);
        self::assertSame([substr(hash('sha256', 'fake-mbx-ana-1'), 0, 12)], $this->ports->rejectedTokens);
        self::assertSame('Bearer fake-mbx-ana-2', $this->graph->requests[1]['authorization']);
        self::assertSame([1, 2], array_map(static fn (ProviderCall $c): int => $c->attempt, $this->ports->calls));

        $this->graph->override = static fn (): MockResponse => MailboxGraphFake::json(['error' => ['code' => 'InvalidAuthenticationToken']], 401);

        try {
            $this->gateway->account($this->session());
            self::fail('Expected a grant failure.');
        } catch (MailboxGrantFailure $failure) {
            self::assertSame(GrantFailureReason::Revoked, $failure->reason);
            self::assertStringNotContainsString('fake-', $failure->getMessage());
        }
    }

    public function testFailureClassification(): void
    {
        $cases = [
            [new GraphException('x', GraphException::REASON_API, 401), GrantFailureReason::Revoked],
            [new GraphException('x', GraphException::REASON_API, 403, 'ErrorInsufficientPermissionsInAccessToken'), GrantFailureReason::ScopeMissing],
            [new GraphException('x', GraphException::REASON_API, 403, 'ErrorAccessDenied'), GrantFailureReason::ConsentMissing],
            [new GraphException('x', GraphException::REASON_API, 404, 'MailboxNotEnabledForRESTAPI'), GrantFailureReason::MailboxNotFound],
        ];
        foreach ($cases as [$exception, $reason]) {
            $failure = GraphMailboxGateway::failure($exception, 'get_message');
            self::assertInstanceOf(MailboxGrantFailure::class, $failure);
            self::assertSame($reason, $failure->reason);
        }
        $provider = [
            [new GraphException('x', GraphException::REASON_THROTTLED, 429, null, null, 9), MailboxProviderFailure::TRANSIENT, 9],
            [GraphException::transport('delta'), MailboxProviderFailure::TRANSIENT, null],
            [new GraphException('x', GraphException::REASON_API, 503), MailboxProviderFailure::TRANSIENT, null],
            [new GraphException('x', GraphException::REASON_TOO_LARGE, 200), MailboxProviderFailure::TOO_LARGE, null],
            [new GraphException('x', GraphException::REASON_API, 404, 'ErrorItemNotFound'), MailboxProviderFailure::NOT_FOUND, null],
            [new GraphException('x', GraphException::REASON_API, 400), MailboxProviderFailure::REFUSED, null],
        ];
        foreach ($provider as [$exception, $kind, $retryAfter]) {
            $failure = GraphMailboxGateway::failure($exception, 'get_message');
            self::assertInstanceOf(MailboxProviderFailure::class, $failure);
            self::assertSame([$kind, $retryAfter], [$failure->reason, $failure->retryAfter]);
        }
        // Only grant / consent / scope 403s pause; another 403 ends the call (audit ZAT-I4).
        $other = GraphMailboxGateway::failure(new GraphException('x', GraphException::REASON_API, 403, 'ErrorItemAccessDenied'), 'get_message');
        self::assertInstanceOf(MailboxProviderFailure::class, $other);
        self::assertSame(['ErrorItemAccessDenied', MailboxProviderFailure::REFUSED], [$other->providerCode, $other->reason]);
        self::assertInstanceOf(MailboxProviderFailure::class, GraphMailboxGateway::failure(new GraphException('x', GraphException::REASON_API, 403), 'get_message'));
        // A refused subscription is not a grant failure (personal accounts stay sweep-only, Q-MB-21).
        self::assertInstanceOf(MailboxProviderFailure::class, GraphMailboxGateway::failure(new GraphException('x', GraphException::REASON_API, 403), 'subscribe', true));
    }

    public function testSubscriptions(): void
    {
        $remote = $this->gateway->subscribe($this->session(), FolderRef::inbox(), 'https://app.example.com/hook', 'state-REDACTED', 1_764_000_000);
        self::assertSame('11111111-0000-4000-8000-000000000001', $remote->id);
        self::assertSame(1_764_000_000, $remote->expiresAt);
        $body = $this->graph->subscribed[0];
        self::assertSame("me/mailFolders('inbox')/messages", $body['resource']);
        self::assertSame(['created', 'https://app.example.com/hook', 'https://app.example.com/hook', 'state-REDACTED', 'v1_2'], [$body['changeType'], $body['notificationUrl'], $body['lifecycleNotificationUrl'], $body['clientState'], $body['latestSupportedTlsVersion']]);

        self::assertSame(1_764_100_000, $this->gateway->renew($this->session(), $remote->id, 1_764_100_000));
        $this->graph->goneSubscriptions['gone'] = true;
        self::assertNull($this->gateway->renew($this->session(), 'gone', 1_764_100_000));
        $this->gateway->unsubscribe($this->session(), 'gone');
        $this->gateway->unsubscribe($this->session(), $remote->id);
        self::assertSame([$remote->id], $this->graph->unsubscribed);

        $this->graph->override = static fn (string $method, string $path): MockResponse => $method === 'POST' ? MailboxGraphFake::json(['id' => 'bad id!'], 201) : MailboxGraphFake::json(['error' => ['code' => 'x']], 500);
        $this->expectFailure(MailboxProviderFailure::REFUSED, fn (): RemoteSubscription => $this->gateway->subscribe($this->session(), FolderRef::inbox(), 'https://app.example.com/hook', 's', 1));
        $this->expectFailure(MailboxProviderFailure::TRANSIENT, fn (): ?int => $this->gateway->renew($this->session(), 'x', 1));
    }

    public function testDeltaPagesFollowOnlyMailboxLinks(): void
    {
        $this->graph->delta['inbox'] = [
            [
                ['id' => 'm1', 'from' => ['emailAddress' => ['address' => 'Pedro@Example.org', 'name' => "Pe\u{202E}dro"]], 'receivedDateTime' => '2026-11-22T10:00:00Z'],
                ['id' => 'm2', '@removed' => ['reason' => 'deleted']],
                ['id' => 'bad id'],
                'junk',
                ['id' => 'm3', 'receivedDateTime' => 'not a date'],
            ],
            [['id' => 'm4', 'sender' => ['emailAddress' => ['address' => 'x@example.org']], 'receivedDateTime' => str_repeat('9', 70)]],
        ];
        $session = $this->session();
        $first   = $this->gateway->changes($session, FolderRef::inbox(), null, 1_763_000_000, 25);
        self::assertStringContainsString('/me/mailFolders/inbox/messages/delta?$select=id,from,sender,receivedDateTime&$filter=receivedDateTime%20ge%202025-11-13T02%3A13%3A20Z', $this->graph->requests[0]['url']);
        self::assertCount(3, $first->items);
        self::assertSame(['m1', false, true, 'pedro@example.org'], [$first->items[0]->id->value, $first->items[0]->removed, $first->items[0]->hasEnvelope, $first->items[0]->from?->email]);
        self::assertSame('Pe dro', $first->items[0]->from?->name);
        self::assertTrue($first->items[1]->removed);
        self::assertFalse($first->items[2]->hasEnvelope);
        self::assertNull($first->items[2]->receivedAt);
        self::assertFalse($first->complete);

        $second = $this->gateway->changes($session, FolderRef::inbox(), $first->cursor, 0, 25);
        self::assertTrue($second->complete);
        self::assertNull($second->items[0]->receivedAt);
        self::assertSame(['delta', 'delta'], array_map(static fn (ProviderCall $c): string => $c->operation, $this->ports->calls));

        foreach ([
            'https://evil.example/v1.0/me/mailFolders/inbox/messages/delta',
            'https://graph.microsoft.com/v1.0/me/messages',
            'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta;x',
            'https://graph.microsoft.com/v1.0/me/mailFolders/%2e%2e/messages/delta',
            'https://graph.microsoft.com/v1.0/users/someone-else@example.org/mailFolders/inbox/messages/delta',
        ] as $link) {
            $this->expectFailure(MailboxProviderFailure::REFUSED, fn (): ChangePage => $this->gateway->changes($session, FolderRef::inbox(), $link, 0, 25));
        }
        // The session's address and the bound account are accepted.
        $this->gateway->changes($session, FolderRef::inbox(), MailboxGraphFake::BASE . "/users('ana@example.com')/mailFolders('inbox')/messages/delta?\$deltatoken=x", 0, 25);
        $bound                 = $this->session(hash('sha256', 'acc-guid-1'));
        $this->graph->override = static fn (string $method, string $path): MockResponse => MailboxGraphFake::json(['value' => [], '@odata.deltaLink' => MailboxGraphFake::BASE . '/users/ACC-GUID-1/mailFolders/inbox/messages/delta?$deltatoken=y']);
        self::assertTrue($this->gateway->changes($bound, FolderRef::inbox(), null, 0, 25)->complete);
        $this->expectFailure(MailboxProviderFailure::REFUSED, fn (): ChangePage => $this->gateway->changes($session, FolderRef::inbox(), null, 0, 25));
    }

    public function testDeltaLinksAreCheckedAgainstTheCursorFolder(): void
    {
        $log     = new RecordingLogger();
        $gateway = new GraphMailboxGateway(new GraphApiClient($this->graph->client(), null), new GraphMessageNormalizer(new MailHtmlSanitizer(RemoteImages::Blocked)), 65_536, $log);
        $session = $this->session();
        // Graph may name the folder by its id: recognised when it is this folder's id (read once per operation, with
        // the same immutable-id form as the delta request).
        $gateway->changes($session, FolderRef::inbox(), MailboxGraphFake::BASE . "/me/mailFolders('FOLDER-inbox')/messages/delta?\$deltatoken=x", 0, 25);
        $gateway->changes($session, FolderRef::inbox(), MailboxGraphFake::BASE . "/me/mailFolders('FOLDER-inbox')/messages/delta?\$deltatoken=x", 0, 25);
        self::assertCount(1, $this->graph->matching('/me/mailFolders/inbox?$select=id'));
        self::assertSame([], $log->records);

        // Another form passes the host and mailbox checks: logged and followed (re-audit ZAT-R1).
        $gateway->changes($session, FolderRef::inbox(), MailboxGraphFake::BASE . "/me/mailFolders('sentitems')/messages/delta?\$deltatoken=x", 0, 25);
        self::assertTrue($log->has('does not recognise; followed'));

        // A refused folder lookup is not fatal; a transient one is retried.
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => str_contains($path, '/me/mailFolders/archive?') ? MailboxGraphFake::json(['error' => ['code' => 'x']], 403) : null;
        $gateway->changes($this->session(), new FolderRef('archive'), MailboxGraphFake::BASE . "/me/mailFolders('x')/messages/delta?\$deltatoken=x", 0, 25);
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => str_contains($path, '/me/mailFolders/archive?') ? MailboxGraphFake::json(['id' => 'bad id']) : null;
        $gateway->changes($this->session(), new FolderRef('archive'), MailboxGraphFake::BASE . "/me/mailFolders('x')/messages/delta?\$deltatoken=x", 0, 25);
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => str_contains($path, '/me/mailFolders/archive?') ? MailboxGraphFake::json(['error' => ['code' => 'x']], 503) : null;
        $this->expectFailure(MailboxProviderFailure::TRANSIENT, fn (): mixed => $gateway->changes($this->session(), new FolderRef('archive'), MailboxGraphFake::BASE . "/me/mailFolders('x')/messages/delta?\$deltatoken=x", 0, 25));
    }

    public function testRemovalsAreConfirmedWithGraph(): void
    {
        $this->graph->messages['moved'] = MailboxGraphFake::message('moved');
        $this->graph->messages['other'] = MailboxGraphFake::message('else');
        self::assertTrue($this->gateway->stillExists($this->session(), new ProviderMessageId('moved')));
        self::assertFalse($this->gateway->stillExists($this->session(), new ProviderMessageId('gone')));
        self::assertFalse($this->gateway->stillExists($this->session(), new ProviderMessageId('other')));
        // An unexpected refusal answers "moved out" (the host never deletes on it); a transient failure is retried.
        $this->graph->override = static fn (): MockResponse => MailboxGraphFake::json(['error' => ['code' => 'ErrorItemAccessDenied']], 403);
        self::assertTrue($this->gateway->stillExists($this->session(), new ProviderMessageId('moved')));
        $this->graph->override = static fn (): MockResponse => MailboxGraphFake::json(['error' => ['code' => 'x']], 503);
        $this->expectFailure(MailboxProviderFailure::TRANSIENT, fn (): bool => $this->gateway->stillExists($this->session(), new ProviderMessageId('moved')));
    }

    public function testDeltaFailures(): void
    {
        foreach ([410 => null, 500 => MailboxProviderFailure::TRANSIENT, 0 => MailboxProviderFailure::REFUSED, 400 => null, 401 => MailboxProviderFailure::REFUSED] as $status => $kind) {
            $this->graph->override = static fn (): MockResponse => match ($status) {
                0       => MailboxGraphFake::json(['value' => []]),
                400     => MailboxGraphFake::json(['error' => ['code' => 'ErrorInvalidSyncStateData']], 400),
                401     => MailboxGraphFake::json(['error' => ['code' => 'BadRequest']], 400),
                default => MailboxGraphFake::json(['error' => ['code' => 'syncStateNotFound']], $status),
            };
            if ($kind !== null) {
                $this->expectFailure($kind, fn (): ChangePage => $this->gateway->changes($this->session(), FolderRef::inbox(), null, 0, 25));

                continue;
            }

            try {
                $this->gateway->changes($this->session(), FolderRef::inbox(), null, 0, 25);
                self::fail('Expected an expired cursor.');
            } catch (CursorExpired $expired) {
                self::assertSame('The mailbox folder cursor expired.', $expired->getMessage());
            }
        }
    }

    public function testFetchBuildsTheEnvelopeAndNormalises(): void
    {
        $this->graph->messages['m1'] = MailboxGraphFake::message('m1', [
            'subject'                => "Re:\tInvoice\x07 42",
            'internetMessageHeaders' => [
                ['name' => 'List-Id', 'value' => 'News <news.example.org>'],
                ['name' => 'In-Reply-To', 'value' => '<parent@client.example.net>'],
                ['name' => 'References', 'value' => '<root@client.example.net> <parent@client.example.net>'],
            ],
        ]);
        $session = $this->session();
        $fetched = $this->gateway->fetch($session, FolderRef::inbox(), new ProviderMessageId('m1'));
        self::assertInstanceOf(FetchedMessage::class, $fetched);
        self::assertStringContainsString('$select=' . GraphMailboxGateway::SELECT, $this->graph->requests[0]['url']);
        self::assertSame(['news.example.org', 'Re: Invoice 42', 'pedro@example.org', true], [$fetched->envelope->listId, $fetched->envelope->subject, $fetched->envelope->from?->email, $fetched->envelope->complete]);

        $message = $this->gateway->normalize($session, $fetched, [], MessageOrigin::Resync);
        self::assertSame('provider:graph:conv-m1', $message->threadKey->value);
        self::assertSame('conv-m1', $message->providerThreadId);
        self::assertSame(['m1@client.example.net', 'parent@client.example.net', ['root@client.example.net', 'parent@client.example.net']], [$message->internetMessageId, $message->inReplyTo, $message->references]);
        self::assertSame(1, $message->remoteImagesBlocked);
        self::assertStringContainsString('data-mail-kit-remote-src="https://tracker.example.net/p.gif"', (string) $message->html);
        self::assertSame('Hello Ana', $message->snippet);
        self::assertSame([], $message->bcc);
        self::assertFalse($message->fromMailbox);
        self::assertSame(['ana@example.com'], array_map(static fn (MailAddress $a): string => $a->email, $message->to));
        self::assertSame('2026-11-20T09:59:00+00:00', $message->sentAt?->format('c'));
        self::assertSame(MessageOrigin::Resync, $message->origin);
        self::assertSame('news.example.org', $message->listId);

        // A message the mailbox sent exposes Bcc; a text body has no HTML.
        $this->graph->messages['m2'] = MailboxGraphFake::message('m2', [
            'from'          => ['emailAddress' => ['address' => 'ANA@example.com']],
            'bccRecipients' => [['emailAddress' => ['address' => 'hidden@example.org']], 'junk', ['emailAddress' => ['address' => 'no-at']]],
            'body'          => ['contentType' => 'text', 'content' => "Plain\ntext"],
        ]);
        $sent = $this->gateway->normalize($session, $this->fetch('m2'), [], MessageOrigin::Sync);
        self::assertTrue($sent->fromMailbox);
        self::assertSame(['hidden@example.org'], array_map(static fn (MailAddress $a): string => $a->email, $sent->bcc));
        self::assertNull($sent->html);
        self::assertSame(0, $sent->remoteImagesBlocked);
        self::assertSame('Plain text', $sent->snippet);

        self::assertNull($this->fetch('m4', ['internetMessageHeaders' => [['name' => 'X-Other', 'value' => 'v']]])->envelope->listId);
        self::assertNull($this->gateway->fetch($session, FolderRef::inbox(), new ProviderMessageId('gone')));
        $this->graph->messages['m3'] = MailboxGraphFake::message('other');
        $this->expectFailure(MailboxProviderFailure::REFUSED, fn (): FetchedMessage => $this->fetch('m3'));
        $this->graph->override = static fn (): MockResponse => MailboxGraphFake::json(['error' => ['code' => 'x']], 502);
        $this->expectFailure(MailboxProviderFailure::TRANSIENT, fn (): FetchedMessage => $this->fetch('m1'));
    }

    public function testOversizedMessagesKeepOnlyTheEnvelope(): void
    {
        $this->graph->messages['big'] = MailboxGraphFake::message('big', ['body' => ['contentType' => 'html', 'content' => str_repeat('x', 70_000)], 'hasAttachments' => true]);
        $fetched                      = $this->fetch('big');
        self::assertTrue($fetched->oversized);
        self::assertSame([], $this->gateway->attachments($this->session(), $fetched));
        $message = $this->gateway->normalize($this->session(), $fetched, [], MessageOrigin::Sync);
        self::assertTrue($message->oversized);
        self::assertSame('', $message->text);

        // Even the envelope is too large: the minimal select.
        $calls                 = 0;
        $this->graph->override = static function (string $method, string $path) use (&$calls): ?MockResponse {
            ++$calls;

            return $calls < 3 ? MailboxGraphFake::json(['id' => 'big', 'pad' => str_repeat('x', 300_000)]) : null;
        };
        self::assertTrue($this->fetch('big')->oversized);
        $last = $this->graph->requests[count($this->graph->requests) - 1] ?? null;
        self::assertStringContainsString('$select=id,internetMessageId,conversationId,subject,from,sender,receivedDateTime', $last['url'] ?? '');

        $calls                 = 0;
        $this->graph->override = static function () use (&$calls): MockResponse {
            ++$calls;

            return $calls < 2 ? MailboxGraphFake::json(['id' => 'big', 'pad' => str_repeat('x', 70_000)]) : MailboxGraphFake::json(['error' => ['code' => 'x']], 500);
        };
        $this->expectFailure(MailboxProviderFailure::TRANSIENT, fn (): FetchedMessage => $this->fetch('big'));
    }

    public function testAttachmentReferences(): void
    {
        $items = [
            ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'a1', 'name' => '..\\evil/inv.pdf', 'contentType' => 'Application/PDF', 'size' => 1234, 'isInline' => false],
            ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'a2', 'name' => 'logo.png', 'contentType' => 'image png', 'size' => -4, 'isInline' => true, 'contentId' => '<logo@x.example>'],
            ['@odata.type' => '#microsoft.graph.itemAttachment', 'id' => 'a3', 'name' => 'Forwarded', 'size' => 10],
            ['@odata.type' => '#microsoft.graph.referenceAttachment', 'id' => 'a4', 'name' => 'Doc'],
            ['@odata.type' => '#microsoft.graph.somethingElse', 'id' => 'a5'],
            ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'bad id'],
            'junk',
        ];
        foreach (range(1, GraphMailboxGateway::MAX_INLINE_LOOKUPS) as $i) {
            $items[] = ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'in' . $i, 'name' => 'i.png', 'isInline' => true, 'contentId' => 'bad cid'];
        }
        $this->graph->attachments['m1'] = $items;
        $this->graph->messages['m1']    = MailboxGraphFake::message('m1', ['hasAttachments' => true]);
        $refs                           = $this->gateway->attachments($this->session(), $this->fetch('m1'));

        self::assertSame(['a1', 'a2', 'a3', 'a4'], array_map(static fn (AttachmentRef $r): string => $r->id->value, array_slice($refs, 0, 4)));
        self::assertSame(['inv.pdf', 'application/pdf', 1234, false, null], [$refs[0]->filename, $refs[0]->contentType, $refs[0]->size, $refs[0]->inline, $refs[0]->contentId]);
        self::assertSame(['application/octet-stream', 0, true, 'logo@x.example'], [$refs[1]->contentType, $refs[1]->size, $refs[1]->inline, $refs[1]->contentId]);
        self::assertSame([AttachmentKind::Item, AttachmentKind::Reference], [$refs[2]->kind, $refs[3]->kind]);
        self::assertNull($refs[4]->contentId);
        self::assertCount(GraphMailboxGateway::MAX_INLINE_LOOKUPS, $this->graph->matching('$select=id,contentId,isInline'));
        self::assertSame([], $this->gateway->attachments($this->session(), $this->fetch('m1', ['hasAttachments' => false])));
    }

    public function testOpenStreamsAFileAttachment(): void
    {
        $pdf                            = "%PDF-1.4\n" . str_repeat('x', 100);
        $this->graph->attachments['m1'] = [
            ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'a1', 'name' => 'invoice.exe', 'size' => 109, '_bytes' => $pdf],
            ['@odata.type' => '#microsoft.graph.itemAttachment', 'id' => 'a2', 'name' => 'mail'],
            ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'a3', 'name' => 'big.bin', 'size' => 10_000, '_bytes' => str_repeat('y', 10_000)],
            ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'a4', 'name' => 'lies.bin', 'size' => 1, '_bytes' => str_repeat('y', 10_000)],
        ];
        $file = $this->gateway->open($this->session(), new ProviderMessageId('m1'), new ProviderAttachmentId('a1'), 1000);
        self::assertSame([109, 'application/pdf', 'invoice.pdf'], [$file->size, $file->contentType, $file->filename]);
        self::assertSame($pdf, stream_get_contents($file->stream));
        $file->close();
        self::assertSame(['get_attachment', 'get_attachment'], array_map(static fn (ProviderCall $c): string => $c->operation, $this->ports->calls));

        $this->expectFailure(MailboxProviderFailure::NOT_DOWNLOADABLE, fn (): FetchedAttachment => $this->gateway->open($this->session(), new ProviderMessageId('m1'), new ProviderAttachmentId('a2'), 1000));
        $this->expectFailure(MailboxProviderFailure::TOO_LARGE, fn (): FetchedAttachment => $this->gateway->open($this->session(), new ProviderMessageId('m1'), new ProviderAttachmentId('a3'), 1000));
        $this->expectFailure(MailboxProviderFailure::TOO_LARGE, fn (): FetchedAttachment => $this->gateway->open($this->session(), new ProviderMessageId('m1'), new ProviderAttachmentId('a4'), 1000));
        $this->expectFailure(MailboxProviderFailure::NOT_FOUND, fn (): FetchedAttachment => $this->gateway->open($this->session(), new ProviderMessageId('m1'), new ProviderAttachmentId('a9'), 1000));

        $this->graph->override = static fn (string $method, string $path): ?MockResponse => str_contains($path, '$value') ? MailboxGraphFake::json(['error' => ['code' => 'x']], 401) : null;
        $this->expectException(MailboxGrantFailure::class);
        $this->gateway->open($this->session(), new ProviderMessageId('m1'), new ProviderAttachmentId('a1'), 1000);
    }

    public function testUnknownTypesDetectAsOctetStream(): void
    {
        $this->graph->attachments['m1'] = [['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'a1', 'name' => '', '_bytes' => '']];
        $file                           = $this->gateway->open($this->session(), new ProviderMessageId('m1'), new ProviderAttachmentId('a1'), 10);
        self::assertSame(0, $file->size);
        self::assertSame('', (string) fread($file->stream, 10));
        self::assertNotSame('', $file->contentType);
    }

    private function session(?string $accountHash = null): MailboxSession
    {
        return new MailboxSession(new MailboxId('mbx-ana'), MailboxProvider::Graph, 'ana@example.com', $accountHash, $this->ports, $this->ports);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function fetch(string $id, array $overrides = []): FetchedMessage
    {
        if ($overrides !== []) {
            $this->graph->messages[$id] = $overrides + ($this->graph->messages[$id] ?? MailboxGraphFake::message($id));
        }
        $fetched = $this->gateway->fetch($this->session(), FolderRef::inbox(), new ProviderMessageId($id));
        self::assertInstanceOf(FetchedMessage::class, $fetched);

        return $fetched;
    }

    /**
     * @param callable(): mixed $call
     */
    private function expectFailure(string $kind, callable $call): void
    {
        try {
            $call();
            self::fail('Expected a provider failure ' . $kind . '.');
        } catch (MailboxProviderFailure $failure) {
            self::assertSame($kind, $failure->reason, $failure->getMessage());
        }
    }
}
