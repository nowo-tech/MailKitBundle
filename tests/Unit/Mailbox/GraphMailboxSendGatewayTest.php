<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Mailbox;

use Closure;
use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Mailbox\Engine\DraftRef;
use Nowo\MailKitBundle\Mailbox\Engine\DraftState;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSession;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Graph\DelegatedGraphCredential;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingAttachment;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingKind;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingMailboxMail;
use Nowo\MailKitBundle\Mailbox\Value\ProviderCall;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Sender\MailAddress;
use Nowo\MailKitBundle\Tests\Support\MailboxGraphFake;
use Nowo\MailKitBundle\Tests\Support\MailboxHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function file_put_contents;
use function str_contains;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * 2.5.0 part 2: the Graph send operations of the gateway on their own: draft answers, MIME limits, the upload session
 * (allow-list, early or missing completion, transport errors, short streams; never the token), draft states and the
 * metering of upload chunks.
 */
final class GraphMailboxSendGatewayTest extends TestCase
{
    use MailboxHarness;

    private MailboxSession $session;

    private DraftRef $draft;

    /** @var Closure(): void */
    private Closure $noop;

    protected function setUp(): void
    {
        $this->harness();
        $this->session                    = new MailboxSession(new MailboxId('mbx-ana'), MailboxProvider::Graph, 'ana@example.com', null, $this->ports, $this->ports);
        $this->draft                      = new DraftRef(new ProviderMessageId('DRAFT-1'), '', null);
        $this->graph->messages['DRAFT-1'] = MailboxGraphFake::message('DRAFT-1', ['isDraft' => true]);
        $this->noop                       = static function (): void {
        };
    }

    public function testDraftAnswers(): void
    {
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => $method === 'POST' ? MailboxGraphFake::json(['id' => 'bad id', 'internetMessageId' => '<a b>'], 201) : null;

        try {
            $this->gateway->createDraft($this->session, $this->mail(), 'x@example.com');
            self::fail('No usable id.');
        } catch (MailboxProviderFailure $failure) {
            self::assertSame(MailboxProviderFailure::REFUSED, $failure->reason);
        }
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => $method === 'POST' ? MailboxGraphFake::json(['id' => 'D1', 'internetMessageId' => '<a b>', 'conversationId' => 'bad conv!'], 201) : null;
        $draft                 = $this->gateway->createDraft($this->session, $this->mail(), 'x@example.com');
        self::assertSame('', $draft->internetMessageId);
        self::assertNull($draft->threadId);
    }

    public function testMimeLimits(): void
    {
        try {
            $this->gateway->createDraft($this->session, $this->mail(text: str_repeat('t', 3_000_000)), 'x@example.com');
            self::fail('Too large.');
        } catch (MailboxProviderFailure $failure) {
            self::assertSame(MailboxProviderFailure::TOO_LARGE, $failure->reason);
        }

        try {
            $noAddress = new MailboxSession(new MailboxId('mbx-ana'), MailboxProvider::Graph, null, null, $this->ports, $this->ports);
            $this->gateway->createDraft($noAddress, $this->mail(), 'x@example.com');
            self::fail('Mime refuses a message without a From address.');
        } catch (MailboxProviderFailure $failure) {
            self::assertSame(MailboxProviderFailure::REFUSED, $failure->reason);
        }
        self::assertSame([], $this->graph->drafts);

        // Bcc-only: the MIME has no recipient header; the Bcc goes by PATCH.
        $this->gateway->createDraft($this->session, $this->mail(to: [], bcc: [new MailAddress('boss@example.org')]), 'x@example.com');
        self::assertStringNotContainsString('boss@example.org', $this->graph->mime['DRAFT-1'] ?? '');
    }

    public function testUploadSessionGuards(): void
    {
        $big = OutgoingAttachment::fromString(str_repeat('z', 3_000_000), 'big.bin');

        $this->graph->override = static fn (string $method, string $path): ?MockResponse => str_contains($path, 'createUploadSession') ? MailboxGraphFake::json(['uploadUrl' => 'https://evil.example/upload/x'], 201) : null;
        $this->expectFailure(fn () => $this->gateway->addAttachment($this->session, $this->draft, $big, $this->noop), MailboxProviderFailure::REFUSED);
        self::assertSame([], $this->graph->matching('evil.example'));

        $this->graph->override = static fn (string $method, string $path): ?MockResponse => $method === 'PUT' ? MailboxGraphFake::json(['id' => 'att'], 201) : null;
        $this->expectFailure(fn () => $this->gateway->addAttachment($this->session, $this->draft, OutgoingAttachment::fromString(str_repeat('z', 5_000_000), 'big.bin'), $this->noop), MailboxProviderFailure::REFUSED);
        self::assertNotSame([], $this->graph->matching('outlook.office.com/upload/', 'DELETE'), 'cancelled');

        $this->graph->override = static fn (string $method, string $path): ?MockResponse => $method === 'PUT' ? MailboxGraphFake::json([]) : null;
        $this->expectFailure(fn () => $this->gateway->addAttachment($this->session, $this->draft, $big, $this->noop), MailboxProviderFailure::REFUSED);

        $this->graph->override = static fn (string $method, string $path): ?MockResponse => $method === 'PUT' ? new MockResponse('', ['error' => 'Connection reset by peer.']) : null;
        $this->expectFailure(fn () => $this->gateway->addAttachment($this->session, $this->draft, $big, $this->noop), MailboxProviderFailure::TRANSIENT);
        $uploads = array_map(static fn (ProviderCall $c): int => $c->status, array_values(array_filter($this->ports->calls, static fn (ProviderCall $c): bool => $c->operation === 'upload')));
        self::assertContains(0, $uploads, 'a failed chunk is metered with status 0');

        $path = (string) tempnam(sys_get_temp_dir(), 'mk');
        file_put_contents($path, str_repeat('s', 100));
        $short = OutgoingAttachment::fromPath($path, 's.bin');
        file_put_contents($path, 'cut');
        $this->graph->override = null;
        $this->expectFailure(fn () => $this->gateway->addAttachment($this->session, $this->draft, $short, $this->noop), MailboxProviderFailure::REFUSED);
        unlink($path);
    }

    public function testDraftStatesAndDelete(): void
    {
        self::assertSame(DraftState::Draft, $this->gateway->draftState($this->session, $this->draft->id));
        $this->graph->deliver('DRAFT-1');
        self::assertSame(DraftState::Sent, $this->gateway->draftState($this->session, $this->draft->id));
        self::assertSame(DraftState::Gone, $this->gateway->draftState($this->session, new ProviderMessageId('NONE')));
        $this->graph->override = static fn (): MockResponse => MailboxGraphFake::json(['id' => 'OTHER', 'isDraft' => true]);
        self::assertSame(DraftState::Unreadable, $this->gateway->draftState($this->session, $this->draft->id));
        $this->graph->override = static fn (): MockResponse => MailboxGraphFake::json(['id' => 'DRAFT-1']);
        self::assertSame(DraftState::Unreadable, $this->gateway->draftState($this->session, $this->draft->id));
        $this->graph->override = null;
        $this->gateway->deleteDraft($this->session, $this->draft->id);
        $this->gateway->deleteDraft($this->session, $this->draft->id);
        self::assertSame(['DRAFT-1', 'DRAFT-1'], $this->graph->deleted);
        self::assertSame('delete_draft', $this->ports->calls[count($this->ports->calls) - 1]->operation);
    }

    public function testUploadChunksAreMeteredAndNeverCarryTheToken(): void
    {
        $api = $this->gatewayApi();
        self::assertSame(201, $api->uploadChunk(new DelegatedGraphCredential($this->session), 'https://outlook.office.com/upload/s', 'abc', 0, 3));
        $api->cancelUpload(new DelegatedGraphCredential($this->session), 'https://outlook.office.com/upload/s');
        self::assertSame('upload', $this->ports->calls[0]->operation);
        self::assertSame(201, $this->ports->calls[0]->status);
        self::assertSame('', $this->graph->requests[0]['authorization']);
        $this->graph->override = static fn (string $method): ?MockResponse => $method === 'PUT' ? MailboxGraphFake::json(['error' => ['code' => 'Nope']], 400) : null;
        $this->expectException(GraphException::class);
        $api->uploadChunk(new DelegatedGraphCredential($this->session), 'https://outlook.office.com/upload/t', 'abc', 0, 3);
    }

    private function gatewayApi(): GraphApiClient
    {
        return new GraphApiClient($this->graph->client(), null);
    }

    /**
     * @param Closure(): mixed $call
     */
    private function expectFailure(Closure $call, string $reason): void
    {
        try {
            $call();
            self::fail('Expected a provider failure.');
        } catch (MailboxProviderFailure $failure) {
            self::assertSame($reason, $failure->reason);
        }
    }

    /**
     * @param list<MailAddress>|null $to
     * @param list<MailAddress> $bcc
     */
    private function mail(string $text = 'Hello', ?array $to = null, array $bcc = []): OutgoingMailboxMail
    {
        return new OutgoingMailboxMail(OutgoingKind::New, null, $to ?? [new MailAddress('pedro@example.org')], [], $bcc, 'Plan', $text, null, [], 'beacon-draft-0001');
    }
}
