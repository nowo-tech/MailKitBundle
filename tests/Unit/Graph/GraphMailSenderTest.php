<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph;

use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\GraphMailbox;
use Nowo\MailKitBundle\Graph\GraphMailboxProviderInterface;
use Nowo\MailKitBundle\Graph\GraphTokenProvider;
use Nowo\MailKitBundle\Graph\Send\GraphAttachment;
use Nowo\MailKitBundle\Graph\Send\GraphMailSender;
use Nowo\MailKitBundle\Graph\Send\GraphOutgoingMail;
use Nowo\MailKitBundle\Graph\Send\GraphRecipient;
use Nowo\MailKitBundle\Graph\Send\GraphSendLimits;
use Nowo\MailKitBundle\Graph\Send\PlusAddress;
use Nowo\MailKitBundle\Tests\Support\GraphApiMock;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;

use function base64_decode;
use function file_put_contents;
use function json_decode;
use function memory_get_peak_usage;
use function print_r;
use function str_contains;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class GraphMailSenderTest extends TestCase
{
    private GraphApiMock $mock;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->mock   = new GraphApiMock();
        $this->logger = new RecordingLogger();
    }

    private function sender(?GraphSendLimits $limits = null): GraphMailSender
    {
        $client    = $this->mock->client();
        $mailboxes = new class implements GraphMailboxProviderInterface {
            public function mailbox(string $profile): GraphMailbox
            {
                return $profile === 'globex' ? GraphApiMock::mailboxB() : GraphApiMock::mailboxA();
            }

            public function defaultProfile(): string
            {
                return 'acme';
            }
        };
        $api = new GraphApiClient($client, new GraphTokenProvider($client, new ArrayAdapter()), GraphApiClient::DEFAULT_UPLOAD_HOSTS, 5.0, $this->logger);

        return new GraphMailSender($mailboxes, $api, $limits ?? new GraphSendLimits(), $this->logger);
    }

    /**
     * @param list<GraphAttachment> $attachments
     * @param list<GraphRecipient> $bcc
     */
    private function mail(array $attachments = [], array $bcc = []): GraphOutgoingMail
    {
        return new GraphOutgoingMail(
            to: [new GraphRecipient('client@example.net', 'Client Name')],
            subject: 'Secret project subject',
            text: 'Private body text',
            html: '<p>Private body html</p>',
            cc: [new GraphRecipient('cc@example.net')],
            bcc: $bcc,
            replyTo: [new GraphRecipient(PlusAddress::compose('projects@example.com', 't-42'))],
            attachments: $attachments,
            messageId: 'own-1@example.com',
            inReplyTo: 'parent@example.net',
            references: ['root@example.net', '<parent@example.net>'],
            fromName: 'Projects',
        );
    }

    public function testSendsADraftWithThreadingHeadersThenSendsIt(): void
    {
        $result = $this->sender()->send($this->mail());

        self::assertSame('draft-projects@example.com-1', $result->graphId);
        self::assertSame('<own-1@example.com>', $result->internetMessageId);
        self::assertSame('conv-1', $result->conversationId);
        self::assertSame('acme', $result->profile);

        $draft = $this->mock->matching('/users/projects%40example.com/messages', 'POST')[0];
        self::assertSame('text/plain', $draft['headers']['content-type']);
        self::assertSame('IdType="ImmutableId"', $draft['headers']['prefer']);
        $mime = $this->mock->draftMime();
        self::assertStringContainsString("Message-ID: <own-1@example.com>\r\n", $mime);
        self::assertStringContainsString("In-Reply-To: <parent@example.net>\r\n", $mime);
        self::assertStringContainsString("References: <root@example.net> <parent@example.net>\r\n", $mime);
        self::assertStringContainsString("Reply-To: projects+t-42@example.com\r\n", $mime);
        self::assertStringContainsString("From: Projects <projects@example.com>\r\n", $mime);
        self::assertStringContainsString('To: Client Name <client@example.net>', $mime);
        self::assertStringContainsString('Cc: cc@example.net', $mime);
        self::assertStringNotContainsString('Bcc', $mime);

        $send = $this->mock->matching('/messages/draft-projects%40example.com-1/send', 'POST');
        self::assertCount(1, $send);
        self::assertCount(0, $this->mock->matching('/messages/draft-projects%40example.com-1', 'PATCH'));
        self::assertCount(0, $this->mock->matching('/messages/draft-projects%40example.com-1', 'DELETE'));

        // No content, address, token or secret in the logs.
        $logs = print_r($this->logger->records, true);
        foreach (['Secret project subject', 'Private body', 'client@example.net', 'projects@example.com', 'tok-', 'REDACTED-TEST'] as $needle) {
            self::assertStringNotContainsString($needle, $logs);
        }
        self::assertTrue($this->logger->has('E-mail sent through Microsoft Graph.'));
    }

    public function testGeneratedMessageIdBccAndProfiles(): void
    {
        $mail   = new GraphOutgoingMail([], 'Hi', 'Hi', bcc: [new GraphRecipient('hidden@example.net', 'Hidden')]);
        $result = $this->sender()->send($mail, 'globex');
        self::assertSame('globex', $result->profile);
        self::assertMatchesRegularExpression('/^<[0-9a-f]{32}@example\.org>$/', $result->internetMessageId);
        $patch = $this->mock->matching('/users/support%40example.org/messages/', 'PATCH');
        self::assertCount(1, $patch);
        self::assertSame(['bccRecipients' => [['emailAddress' => ['address' => 'hidden@example.net', 'name' => 'Hidden']]]], json_decode($patch[0]['body'], true));
        self::assertStringNotContainsString('hidden@example.net', $this->mock->draftMime());
        self::assertStringNotContainsString('To:', $this->mock->draftMime());

        $this->sender()->send(new GraphOutgoingMail([new GraphRecipient('a@example.net')], 'Hi', 'Hi', bcc: [new GraphRecipient('b@example.net')]));
        $patch = $this->mock->matching('/users/projects%40example.com/messages/', 'PATCH');
        self::assertSame(['bccRecipients' => [['emailAddress' => ['address' => 'b@example.net']]]], json_decode($patch[0]['body'], true));
    }

    public function testRewrittenMessageIdIsReported(): void
    {
        $this->mock->reportedMessageId = '<exchange-generated@prod.outlook.com>';
        self::assertSame('<exchange-generated@prod.outlook.com>', $this->sender()->send($this->mail())->internetMessageId);
        self::assertTrue($this->logger->has('replaced the Message-ID'));

        $this->mock->reportedMessageId = '';
        self::assertSame('<own-1@example.com>', $this->sender()->send($this->mail())->internetMessageId);
    }

    public function testSmallAndLargeAttachments(): void
    {
        $path  = (string) tempnam(sys_get_temp_dir(), 'mk-graph');
        $large = GraphMailSender::LARGE_ATTACHMENT_BYTES + GraphMailSender::CHUNK_BYTES + 10;
        file_put_contents($path, str_repeat('L', $large));
        $small = GraphAttachment::fromString('small-bytes', 'small.txt', 'text/plain');
        $logo  = GraphAttachment::fromString('png', 'logo.png', 'image/png', true, 'logo@mk');

        $peak = memory_get_peak_usage();
        $this->sender()->send($this->mail([$small, $logo, GraphAttachment::fromPath($path, '../big.bin', 'application/octet-stream')]));
        unlink($path);

        $posts = $this->mock->matching('/attachments', 'POST');
        $plain = [];
        foreach ($posts as $post) {
            if (!str_contains($post['url'], 'createUploadSession')) {
                $plain[] = json_decode($post['body'], true);
            }
        }
        self::assertSame(['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => 'small.txt', 'contentType' => 'text/plain', 'contentBytes' => 'c21hbGwtYnl0ZXM=', 'isInline' => false], $plain[0]);
        self::assertTrue($plain[1]['isInline']);
        self::assertSame('logo@mk', $plain[1]['contentId']);
        self::assertSame('png', base64_decode($plain[1]['contentBytes'], true));

        $session = $this->mock->matching('createUploadSession', 'POST');
        self::assertCount(1, $session);
        self::assertSame(['AttachmentItem' => ['attachmentType' => 'file', 'name' => 'big.bin', 'size' => $large, 'contentType' => 'application/octet-stream', 'isInline' => false]], json_decode($session[0]['body'], true));

        $puts = $this->mock->matching('outlook.office.com/upload/', 'PUT');
        self::assertCount(2, $puts);
        self::assertSame('bytes 0-' . (GraphMailSender::CHUNK_BYTES - 1) . '/' . $large, $puts[0]['headers']['content-range']);
        self::assertSame('bytes ' . GraphMailSender::CHUNK_BYTES . '-' . ($large - 1) . '/' . $large, $puts[1]['headers']['content-range']);
        self::assertSame($large - GraphMailSender::CHUNK_BYTES, strlen($puts[1]['body']));
        self::assertArrayNotHasKey('authorization', $puts[0]['headers']);
        self::assertCount(1, $this->mock->matching('/send', 'POST'));
        self::assertLessThan(64 * 1024 * 1024, memory_get_peak_usage() - $peak + 1);

        // Inline large attachment with a Content-ID.
        $this->mock->requests = [];
        $this->sender()->send($this->mail([GraphAttachment::fromString(str_repeat('i', GraphMailSender::LARGE_ATTACHMENT_BYTES), 'inline.png', 'image/png', true, 'big@mk')]));
        $session = json_decode($this->mock->matching('createUploadSession', 'POST')[0]['body'], true);
        self::assertSame('big@mk', $session['AttachmentItem']['contentId']);
        self::assertTrue($session['AttachmentItem']['isInline']);
    }

    public function testUploadFailuresDeleteTheDraftAndCancelTheSession(): void
    {
        $big = static fn (): GraphAttachment => GraphAttachment::fromString(str_repeat('x', GraphMailSender::LARGE_ATTACHMENT_BYTES), 'big.bin');

        // Upload URL outside the allowed hosts (SSRF guard).
        $this->mock->override = static fn (string $method, string $url): ?MockResponse => str_contains($url, 'createUploadSession')
            ? GraphApiMock::json(['uploadUrl' => 'https://169.254.169.254/latest'], 201)
            : null;
        $this->assertFailure($this->mail([$big()]), GraphException::REASON_UNTRUSTED_URL);
        self::assertCount(1, $this->mock->matching('/messages/draft-projects%40example.com-1', 'DELETE'));
        self::assertCount(0, $this->mock->matching('169.254.169.254'));
        self::assertCount(0, $this->mock->matching('/send', 'POST'));

        // No upload URL at all.
        $this->mock->override = static fn (string $method, string $url): ?MockResponse => str_contains($url, 'createUploadSession')
            ? GraphApiMock::json([], 201)
            : null;
        $this->assertFailure($this->mail([$big()]), GraphException::REASON_API);

        // The upload ends early (201 before the last chunk), then a session that never completes (200 at the end).
        foreach ([201, 200] as $status) {
            $this->mock->requests = [];
            $this->mock->override = static fn (string $method, string $url): ?MockResponse => $method === 'PUT'
                ? new MockResponse('', ['http_code' => $status])
                : null;
            $this->assertFailure($this->mail([GraphAttachment::fromString(str_repeat('x', GraphMailSender::CHUNK_BYTES + 1), 'big.bin')]), GraphException::REASON_API);
            self::assertCount(1, $this->mock->matching('outlook.office.com', 'DELETE'));
        }

        // A definite refusal of the send and the draft cannot be deleted either: logged, original error rethrown.
        $this->mock->requests = [];
        $this->mock->override = static fn (string $method, string $url): ?MockResponse => match (true) {
            $method === 'DELETE'        => GraphApiMock::json(['error' => ['code' => 'ErrorInternalServerError']], 500),
            str_contains($url, '/send') => GraphApiMock::json(['error' => ['code' => 'ErrorInvalidRecipients']], 400),
            default                     => null,
        };
        $this->assertFailure($this->mail(), GraphException::REASON_API);
        self::assertTrue($this->logger->has('draft of a failed send could not be deleted'));
    }

    /**
     * Audit NK-1: an ambiguous send failure (network, 5xx, throttling) never deletes a message that may have been sent.
     */
    public function testAmbiguousSendFailures(): void
    {
        $ambiguous = [
            'timeout'  => static fn (string $method, string $url): ?MockResponse => str_contains($url, '/send') ? throw new TransportException('timeout') : null,
            'http 503' => static fn (string $method, string $url): ?MockResponse => str_contains($url, '/send') ? new MockResponse('', ['http_code' => 503]) : null,
            'http 500' => static fn (string $method, string $url): ?MockResponse => str_contains($url, '/send') ? GraphApiMock::json(['error' => ['code' => 'ErrorInternalServerError']], 500) : null,
        ];
        foreach ($ambiguous as $case => $override) {
            // Still a draft: not sent, so the draft is deleted and the original (retryable) error is rethrown.
            $this->mock->requests   = [];
            $this->mock->override   = $override;
            $this->mock->draftState = true;
            try {
                $this->sender()->send($this->mail());
                self::fail($case . ': expected a failure.');
            } catch (GraphException $e) {
                self::assertTrue($e->isRetryable(), $case);
            }
            self::assertCount(1, $this->mock->matching('$select=isDraft', 'GET'), $case);
            self::assertCount(1, $this->mock->matching('/messages/draft-', 'DELETE'), $case);

            // No longer a draft: it was sent; success, nothing deleted.
            $this->mock->requests   = [];
            $this->mock->draftState = false;
            $result                 = $this->sender()->send($this->mail());
            self::assertSame('<own-1@example.com>', $result->internetMessageId, $case);
            self::assertCount(0, $this->mock->matching('/messages/draft-', 'DELETE'), $case);
            self::assertTrue($this->logger->has('send call failed but the message was sent'));

            // State unknown: keep the draft, not retryable.
            $this->mock->requests   = [];
            $this->mock->draftState = null;
            try {
                $this->sender()->send($this->mail());
                self::fail($case . ': expected an ambiguous failure.');
            } catch (GraphException $e) {
                self::assertSame(GraphException::REASON_AMBIGUOUS, $e->reason, $case);
                self::assertFalse($e->isRetryable());
                self::assertStringContainsString('not retried', $e->getMessage());
            }
            self::assertCount(0, $this->mock->matching('/messages/draft-', 'DELETE'), $case);
        }
        self::assertTrue($this->logger->has('outcome unknown'));

        // A malformed state answer counts as unknown.
        $this->mock->requests = [];
        $this->mock->override = static fn (string $method, string $url): ?MockResponse => match (true) {
            str_contains($url, '/send')           => new MockResponse('', ['http_code' => 503]),
            str_contains($url, '$select=isDraft') => GraphApiMock::json(['isDraft' => 'yes']),
            default                               => null,
        };
        $this->assertFailure($this->mail(), GraphException::REASON_AMBIGUOUS);
        $this->mock->override   = null;
        $this->mock->draftState = true;
    }

    public function testAttachmentPathBoundary(): void
    {
        $this->sender()->send($this->mail([GraphAttachment::fromString(str_repeat('a', GraphMailSender::LARGE_ATTACHMENT_BYTES - 1), 'below.bin')]));
        self::assertCount(0, $this->mock->matching('createUploadSession'));
        $this->mock->requests = [];
        $this->sender()->send($this->mail([GraphAttachment::fromString(str_repeat('a', GraphMailSender::LARGE_ATTACHMENT_BYTES), 'at.bin')]));
        self::assertCount(1, $this->mock->matching('createUploadSession'));
    }

    public function testDraftWithoutIdAndShortAttachment(): void
    {
        $this->mock->override = static fn (string $method, string $url): ?MockResponse => $method === 'POST' && str_contains($url, '/messages') && !str_contains($url, '/messages/')
            ? GraphApiMock::json(['internetMessageId' => '<x@y>'], 201)
            : null;
        $this->assertFailure($this->mail(), GraphException::REASON_API);

        $this->mock->override = null;
        $path                 = (string) tempnam(sys_get_temp_dir(), 'mk-graph');
        file_put_contents($path, '0123456789');
        $attachment = GraphAttachment::fromPath($path, 'short.txt');
        file_put_contents($path, '0123');
        try {
            $this->assertFailure($this->mail([$attachment]), GraphException::REASON_INVALID_MESSAGE);
        } finally {
            unlink($path);
        }
    }

    public function testLimitsAreCheckedBeforeAnyCall(): void
    {
        $limits = new GraphSendLimits(1, 10, 40);
        $this->assertFailure($this->mail([GraphAttachment::fromString('a', 'a'), GraphAttachment::fromString('b', 'b')]), GraphException::REASON_INVALID_MESSAGE, $limits, 'Too many attachments');
        $this->assertFailure($this->mail([GraphAttachment::fromString(str_repeat('a', 11), 'a')]), GraphException::REASON_INVALID_MESSAGE, $limits, 'attachment exceeds');
        $this->assertFailure($this->mail([GraphAttachment::fromString(str_repeat('a', 10), 'a')]), GraphException::REASON_INVALID_MESSAGE, $limits, 'e-mail exceeds');
        $this->assertFailure(
            new GraphOutgoingMail([new GraphRecipient('a@example.net')], 's', str_repeat('a', GraphMailSender::MAX_MIME_BYTES)),
            GraphException::REASON_INVALID_MESSAGE,
            new GraphSendLimits(maxMessageBytes: 10_000_000),
            'body is too large',
        );
        self::assertSame([], $this->mock->requests);

        $this->assertFailure(new GraphOutgoingMail([new GraphRecipient('a@example.net')], 's', 'x', inReplyTo: 'a,b@example.com'), GraphException::REASON_INVALID_MESSAGE, null, 'could not be rendered');
    }

    private function assertFailure(GraphOutgoingMail $mail, string $reason, ?GraphSendLimits $limits = null, ?string $message = null): void
    {
        try {
            $this->sender($limits)->send($mail);
            self::fail('Expected a Graph failure.');
        } catch (GraphException $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
            if ($message !== null) {
                self::assertStringContainsString($message, $e->getMessage());
            }
            self::assertStringNotContainsString('REDACTED-TEST', $e->getMessage());
            self::assertStringNotContainsString('tok-', $e->getMessage());
            self::assertStringNotContainsString('authtoken', $e->getMessage());
        }
    }
}
