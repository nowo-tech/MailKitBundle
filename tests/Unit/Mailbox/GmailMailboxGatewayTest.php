<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Mailbox;

use Closure;
use LogicException;
use Nowo\MailKitBundle\Mailbox\Engine\ChangePage;
use Nowo\MailKitBundle\Mailbox\Engine\DraftRef;
use Nowo\MailKitBundle\Mailbox\Engine\DraftState;
use Nowo\MailKitBundle\Mailbox\Engine\FetchedMessage;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSession;
use Nowo\MailKitBundle\Mailbox\Engine\PreparedMessage;
use Nowo\MailKitBundle\Mailbox\Engine\ProviderAccount;
use Nowo\MailKitBundle\Mailbox\Engine\RemoteSubscription;
use Nowo\MailKitBundle\Mailbox\Engine\SubmittedMessage;
use Nowo\MailKitBundle\Mailbox\Engine\SubmitTokenRejected;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Gmail\GmailApiClient;
use Nowo\MailKitBundle\Mailbox\Gmail\GmailMailboxGateway;
use Nowo\MailKitBundle\Mailbox\Gmail\GmailScopes;
use Nowo\MailKitBundle\Mailbox\Value\FetchedAttachment;
use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\MessageEnvelope;
use Nowo\MailKitBundle\Mailbox\Value\MessageOrigin;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingAttachment;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingKind;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingMailboxMail;
use Nowo\MailKitBundle\Mailbox\Value\ProviderAttachmentId;
use Nowo\MailKitBundle\Mailbox\Value\ProviderCall;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Mailbox\Value\SyncedMessage;
use Nowo\MailKitBundle\Sender\MailAddress;
use Nowo\MailKitBundle\Tests\Support\MailboxGmailFake;
use Nowo\MailKitBundle\Tests\Support\MemoryMailboxPorts;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_map;
use function serialize;
use function str_repeat;
use function var_export;

/**
 * Phase B2: the send-only Gmail gateway and its client against the fake Google endpoints. Scopes, account check
 * (userinfo, verified address only, one retry after a 401), the RFC 5322 message (Bcc header, sanitised HTML, inline
 * parts), the single submit, the error table and the refusal of everything that reads the mailbox. Fake tokens only.
 */
final class GmailMailboxGatewayTest extends TestCase
{
    private MailboxGmailFake $google;

    private MemoryMailboxPorts $ports;

    private RecordingLogger $log;

    private GmailMailboxGateway $gateway;

    protected function setUp(): void
    {
        $this->google  = new MailboxGmailFake();
        $this->ports   = (new MemoryMailboxPorts())->add(MemoryMailboxPorts::gmailSettings());
        $this->log     = new RecordingLogger();
        $this->gateway = new GmailMailboxGateway(new GmailApiClient($this->google->client(), 5.0, $this->log));
    }

    public function testScopesAreExactlyTheSendOnlyOnes(): void
    {
        self::assertSame(GmailScopes::REQUESTED[2], MailboxGmailFake::token()->scopes[2]);
        self::assertSame(MailboxProvider::Gmail, $this->gateway->provider());
    }

    public function testAccountReadsTheVerifiedAddressAndMetersTheCall(): void
    {
        $account = $this->gateway->account($this->session());
        self::assertSame(MailboxGmailFake::SUB, $account->id);
        self::assertSame(['ana@example.com'], $account->addresses);
        self::assertSame('Bearer fake-mbx-gmail-1', $this->google->requests[0]['authorization']);
        self::assertSame(['account'], array_map(static fn (ProviderCall $c): string => $c->operation, $this->ports->calls));
        self::assertSame([MailboxProvider::Gmail, 200, 0], [$this->ports->calls[0]->provider, $this->ports->calls[0]->status, $this->ports->calls[0]->quotaUnits]);

        // An unverified or missing address never matches a directory address.
        $this->google->userinfo = ['sub' => MailboxGmailFake::SUB, 'email' => 'ana@example.com', 'email_verified' => false];
        self::assertSame([], $this->gateway->account($this->session())->addresses);
        $this->google->userinfo = ['sub' => MailboxGmailFake::SUB];
        self::assertSame([], $this->gateway->account($this->session())->addresses);
        $this->google->userinfo = ['sub' => MailboxGmailFake::SUB, 'email' => str_repeat('a', 250) . '@example.com', 'email_verified' => true];
        self::assertSame([], $this->gateway->account($this->session())->addresses);

        $this->google->userinfo = ['sub' => 'bad sub', 'email' => 'ana@example.com', 'email_verified' => true];
        $this->expectFailure(MailboxProviderFailure::REFUSED, fn (): ProviderAccount => $this->gateway->account($this->session()));
    }

    public function testATokenWithoutTheSendScopePausesBeforeAnyCall(): void
    {
        $this->ports->gmailScopes = ['openid', 'email'];

        try {
            $this->gateway->account($this->session());
            self::fail('Scope missing.');
        } catch (MailboxGrantFailure $failure) {
            self::assertSame(GrantFailureReason::ScopeMissing, $failure->reason);
        }
        self::assertSame([], $this->google->requests);
    }

    public function testAccountRetriesOnceWithAFreshTokenAfterA401(): void
    {
        $calls                  = 0;
        $this->google->override = static function () use (&$calls): ?MockResponse {
            return ++$calls === 1 ? MailboxGmailFake::json([], 401) : null;
        };
        $session = $this->session();
        self::assertSame(MailboxGmailFake::SUB, $this->gateway->account($session)->id);
        self::assertCount(1, $this->ports->rejectedTokens);
        self::assertSame('Bearer fake-mbx-gmail-2', $this->google->requests[1]['authorization']);

        $this->google->override = static fn (): MockResponse => MailboxGmailFake::json([], 401);

        try {
            $this->gateway->account($this->session());
            self::fail('Revoked.');
        } catch (MailboxGrantFailure $failure) {
            self::assertSame(GrantFailureReason::Revoked, $failure->reason);
            self::assertStringNotContainsString('fake-', $failure->getMessage());
        }
    }

    public function testPrepareBuildsOneMessageWithBccHeaderAndSanitisedHtml(): void
    {
        $inline = OutgoingAttachment::fromString('PNGDATA', 'logo.png', 'image/png', true, 'logo@mail-kit');
        $file   = OutgoingAttachment::fromString('hello', 'note.txt', 'text/plain');
        $mail   = new OutgoingMailboxMail(OutgoingKind::New, null, [new MailAddress('pedro@example.org', 'Pedro')], [new MailAddress('cc@example.org')], [new MailAddress('boss@example.org', 'Boss')], 'Plan', 'Hello', '<p onclick="x()">Hi <img src="https://tracker.example.net/p.gif"><img src="cid:logo@mail-kit"></p><script>alert(1)</script>', [$inline, $file], 'gmail-send-key-0001');

        $prepared = $this->gateway->prepare($this->session(), $mail, 'abc.mailkit@example.com');
        self::assertSame('abc.mailkit@example.com', $prepared->messageId);
        self::assertStringStartsWith("Bcc: Boss <boss@example.org>\r\n", $prepared->mime);
        self::assertStringContainsString('From: ana@example.com', $prepared->mime);
        self::assertStringContainsString('To: Pedro <pedro@example.org>', $prepared->mime);
        self::assertStringContainsString('Cc: cc@example.org', $prepared->mime);
        self::assertStringContainsString('Message-ID: <abc.mailkit@example.com>', $prepared->mime);
        self::assertStringContainsString('Content-ID: <logo@mail-kit>', $prepared->mime);
        self::assertStringContainsString('filename=note.txt', $prepared->mime);
        self::assertStringNotContainsString('<script', $prepared->mime);
        self::assertStringNotContainsString('onclick', $prepared->mime);
        self::assertStringNotContainsString('tracker.example.net', $prepared->mime);
        self::assertSame([], $this->google->requests, 'prepare makes no call');
        self::assertStringNotContainsString('pedro', var_export($prepared->__debugInfo(), true));

        // Bcc only: Mime needs a recipient header; Gmail gets the Bcc header only.
        $bccOnly = $this->gateway->prepare($this->session(), new OutgoingMailboxMail(OutgoingKind::New, null, [], [], [new MailAddress('boss@example.org')], 'Plan', 'Hello', null, [OutgoingAttachment::fromPath(__FILE__)], 'gmail-send-key-0002'), 'def.mailkit@example.com');
        self::assertStringStartsWith("Bcc: boss@example.org\r\n", $bccOnly->mime);
        self::assertStringNotContainsString("\r\nTo:", $bccOnly->mime);

        $this->expectException(LogicException::class);
        serialize($prepared);
    }

    public function testPrepareRefusesRepliesBadContentIdsAndOversizedMessages(): void
    {
        $reply = new OutgoingMailboxMail(OutgoingKind::Reply, new ProviderMessageId('orig'), [new MailAddress('pedro@example.org')], [], [], null, 'Hi', null, [], 'gmail-send-key-0003');
        $this->expectFailure(MailboxProviderFailure::REFUSED, fn (): PreparedMessage => $this->gateway->prepare($this->session(), $reply, 'x.mailkit@example.com'));

        $cid = new OutgoingMailboxMail(OutgoingKind::New, null, [new MailAddress('pedro@example.org')], [], [], 'Plan', 'Hi', null, [OutgoingAttachment::fromString('x', 'x.png', 'image/png', true, 'logo')], 'gmail-send-key-0004');
        $this->expectFailure(MailboxProviderFailure::REFUSED, fn (): PreparedMessage => $this->gateway->prepare($this->session(), $cid, 'x.mailkit@example.com'));

        $badId = new OutgoingMailboxMail(OutgoingKind::New, null, [new MailAddress('pedro@example.org')], [], [], 'Plan', 'Hi', null, [], 'gmail-send-key-0005');
        $this->expectFailure(MailboxProviderFailure::REFUSED, fn (): PreparedMessage => $this->gateway->prepare($this->session(), $badId, 'no-at-sign'));

        $big = new OutgoingMailboxMail(OutgoingKind::New, null, [new MailAddress('pedro@example.org')], [], [], 'Plan', 'Hi', null, [OutgoingAttachment::fromString(str_repeat('x', 28_000_000), 'big.bin')], 'gmail-send-key-0006');
        $this->expectFailure(MailboxProviderFailure::TOO_LARGE, fn (): PreparedMessage => $this->gateway->prepare($this->session(), $big, 'x.mailkit@example.com'));
    }

    public function testSubmitSendsTheRawMessageOnceWithQuotaUnits(): void
    {
        $sent = $this->gateway->submit($this->session(), new PreparedMessage("Subject: x\r\n\r\nHi", 'x.mailkit@example.com'));
        self::assertSame('18c0000000000001', $sent->id->value);
        self::assertSame('18c0000000000001', $sent->threadId);
        self::assertSame(['Subject: x' . "\r\n\r\nHi"], $this->google->sent);
        self::assertSame('message/rfc822', $this->google->to(GmailApiClient::SEND_URL)[0]['contentType']);
        self::assertSame([['send', 200, 100]], array_map(static fn (ProviderCall $c): array => [$c->operation, $c->status, $c->quotaUnits], $this->ports->calls));

        // A thread id that is not an id is dropped; a missing message id is an unusable answer.
        $this->google->override = static fn (): MockResponse => MailboxGmailFake::json(['id' => 'abc', 'threadId' => 'bad id']);
        self::assertNull($this->gateway->submit($this->session(), new PreparedMessage('x', 'x@y'))->threadId);
        $this->google->override = static fn (): MockResponse => MailboxGmailFake::json(['labelIds' => ['SENT']]);
        $this->expectFailure(MailboxProviderFailure::REFUSED, fn (): SubmittedMessage => $this->gateway->submit($this->session(), new PreparedMessage('x', 'x@y')), 200);
    }

    public function testSubmitReportsA401WithoutRetrying(): void
    {
        $this->google->override = static fn (): MockResponse => MailboxGmailFake::json([], 401);

        try {
            $this->gateway->submit($this->session(), new PreparedMessage('x', 'x@y'));
            self::fail('401.');
        } catch (SubmitTokenRejected $rejected) {
            self::assertSame('Gmail refused the token of the submit (HTTP 401).', $rejected->getMessage());
        }
        self::assertCount(1, $this->google->requests);
        self::assertCount(1, $this->ports->rejectedTokens);

        $this->google->override = static fn (): MockResponse => MailboxGmailFake::error(400, 'invalidArgument', 'INVALID_ARGUMENT');
        $this->expectFailure(MailboxProviderFailure::REFUSED, fn (): SubmittedMessage => $this->gateway->submit($this->session(), new PreparedMessage('x', 'x@y')), 400);
    }

    /**
     * @return iterable<string, array{int, array<mixed>, string}>
     */
    public static function errors(): iterable
    {
        $error = static fn (int $code, ?string $reason, ?string $status = null, array $details = []): array => ['error' => ['code' => $code] + ($reason !== null ? ['errors' => [['reason' => $reason]]] : []) + ($status !== null ? ['status' => $status] : []) + ($details !== [] ? ['details' => $details] : [])];

        yield '401' => [401, [], 'grant:revoked'];
        yield 'scope (errors)' => [403, $error(403, 'insufficientPermissions'), 'grant:scope_missing'];
        yield 'scope (details)' => [403, $error(403, null, 'PERMISSION_DENIED', [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'ACCESS_TOKEN_SCOPE_INSUFFICIENT'], 'junk']), 'grant:scope_missing'];
        yield 'domain policy' => [403, $error(403, 'domainPolicy'), 'grant:consent_missing'];
        yield 'gmail disabled' => [400, $error(400, 'failedPrecondition'), 'grant:mailbox_not_found'];
        yield 'daily limit' => [403, $error(403, 'dailyLimitExceeded'), 'transient'];
        yield 'rate limit 429' => [429, $error(429, 'rateLimitExceeded', 'RESOURCE_EXHAUSTED'), 'transient'];
        yield '503' => [503, [], 'transient'];
        yield '404' => [404, $error(404, 'notFound'), 'not_found'];
        yield '413' => [413, [], 'too_large'];
        yield 'api not enabled 403' => [403, $error(403, 'accessNotConfigured', 'PERMISSION_DENIED'), 'refused'];
        yield 'bad reason token' => [400, $error(400, 'bad reason!'), 'refused'];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('errors')]
    public function testErrorTable(int $status, array $data, string $expected): void
    {
        $failure = GmailMailboxGateway::failure($status, $data, 7, 'send');
        $actual  = $failure instanceof MailboxGrantFailure ? 'grant:' . $failure->reason->value : $failure->reason;
        self::assertSame($expected, $actual);
        if ($failure instanceof MailboxProviderFailure) {
            self::assertSame([$status, 7], [$failure->status, $failure->retryAfter]);
        }
    }

    public function testEverythingThatReadsTheMailboxRefuses(): void
    {
        $session  = $this->session();
        $id       = new ProviderMessageId('m1');
        $draft    = new DraftRef($id, '', null);
        $mail     = new OutgoingMailboxMail(OutgoingKind::New, null, [new MailAddress('pedro@example.org')], [], [], 'Plan', 'Hi', null, [], 'gmail-send-key-0007');
        $fetched  = new FetchedMessage($id, FolderRef::inbox(), new MessageEnvelope(new MailboxId('mbx-gmail'), FolderRef::inbox(), $id, null, null, null, null, null, true), [], false);
        $refusals = [
            fn (): RemoteSubscription => $this->gateway->subscribe($session, FolderRef::inbox(), 'https://app.example.com/hook', 'state', 0),
            fn (): ?int => $this->gateway->renew($session, 'sub', 0),
            fn () => $this->gateway->unsubscribe($session, 'sub'),
            fn (): ChangePage => $this->gateway->changes($session, FolderRef::inbox(), null, 0, 10),
            fn (): ?\Nowo\MailKitBundle\Mailbox\Engine\FetchedMessage => $this->gateway->fetch($session, FolderRef::inbox(), $id),
            fn (): bool => $this->gateway->stillExists($session, $id),
            fn (): array => $this->gateway->attachments($session, $fetched),
            fn (): SyncedMessage => $this->gateway->normalize($session, $fetched, [], MessageOrigin::Sync),
            fn (): FetchedAttachment => $this->gateway->open($session, $id, new ProviderAttachmentId('a1'), 10),
            fn (): DraftRef => $this->gateway->createDraft($session, $mail, 'x@y'),
            fn () => $this->gateway->updateDraft($session, $draft, $mail),
            fn () => $this->gateway->addAttachment($session, $draft, OutgoingAttachment::fromString('a', 'a.txt'), static function (): void {}),
            fn () => $this->gateway->sendDraft($session, $id),
            fn () => $this->gateway->deleteDraft($session, $id),
        ];
        foreach ($refusals as $refusal) {
            $this->expectFailure(MailboxProviderFailure::REFUSED, $refusal);
        }
        self::assertSame(DraftState::Unreadable, $this->gateway->draftState($session, $id));
        self::assertCount(0, $this->google->requests);
    }

    public function testTheClientCallsOnlyTheFixedEndpointsAndNeverLeaksTheToken(): void
    {
        $api = new GmailApiClient($this->google->client(), 5.0, $this->log);
        $this->expectFailure(MailboxProviderFailure::REFUSED, fn (): array => $api->request($this->session(), 'GET', 'https://evil.example.com/userinfo', [], 'account', 0, 100));

        // Transport failure: replaced, never chained; the call is still metered with status 0.
        $failing = new GmailApiClient(new MockHttpClient(static function (): never {
            throw new RuntimeException('boom fake-mbx-gmail-1');
        }), 5.0, $this->log);

        try {
            $failing->request($this->session(), 'GET', GmailApiClient::USERINFO_URL, [], 'account', 0, 100);
            self::fail('Transport.');
        } catch (MailboxProviderFailure $failure) {
            self::assertTrue($failure->isRetryable());
            self::assertNull($failure->status);
            self::assertNull($failure->getPrevious());
            self::assertStringNotContainsString('fake-', $failure->getMessage());
        }
        self::assertTrue($this->log->has('Gmail call failed.'));

        // Over the size limit; an empty or non-JSON body decodes to nothing; Retry-After is read.
        $this->google->override = static fn (): MockResponse => MailboxGmailFake::json(['big' => str_repeat('x', 500)]);
        $this->expectFailure(MailboxProviderFailure::TOO_LARGE, fn (): array => $api->request($this->session(), 'GET', GmailApiClient::USERINFO_URL, [], 'account', 0, 100), 200);
        $this->google->override = static fn (): MockResponse => new MockResponse('not json', ['http_code' => 503, 'response_headers' => ['retry-after' => '30']]);
        self::assertSame(['status' => 503, 'data' => [], 'retryAfter' => 30], $api->request($this->session(), 'GET', GmailApiClient::USERINFO_URL, [], 'account', 0, 100));
        $this->google->override = static fn (): MockResponse => new MockResponse('', ['http_code' => 204]);
        self::assertSame(['status' => 204, 'data' => [], 'retryAfter' => null], $api->request($this->session(), 'GET', GmailApiClient::USERINFO_URL, [], 'account', 0, 100));
        $this->google->override = static fn (): MockResponse => new MockResponse('"text"', ['http_code' => 200]);
        self::assertCount(0, $api->request($this->session(), 'GET', GmailApiClient::USERINFO_URL, [], 'account', 0, 100)['data']);
    }

    private function session(): MailboxSession
    {
        return new MailboxSession(new MailboxId('mbx-gmail'), MailboxProvider::Gmail, 'ana@example.com', null, $this->ports, $this->ports, $this->log);
    }

    /**
     * @param Closure(): mixed $call
     */
    private function expectFailure(string $reason, Closure $call, ?int $status = null): void
    {
        try {
            $call();
            self::fail('Expected a provider failure.');
        } catch (MailboxProviderFailure $failure) {
            self::assertSame($reason, $failure->reason);
            if ($status !== null) {
                self::assertSame($status, $failure->status);
            }
        }
    }
}
