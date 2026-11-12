<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph\Inbound;

use LogicException;
use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\GraphTokenProvider;
use Nowo\MailKitBundle\Graph\Inbound\GraphInboundProcessor;
use Nowo\MailKitBundle\Graph\Inbound\GraphMessageNormalizer;
use Nowo\MailKitBundle\Graph\Inbound\InboundAction;
use Nowo\MailKitBundle\Graph\Inbound\InboundAttachment;
use Nowo\MailKitBundle\Graph\Inbound\InboundHtmlSanitizer;
use Nowo\MailKitBundle\Graph\Inbound\InboundLimits;
use Nowo\MailKitBundle\Tests\Support\GraphApiMock;
use Nowo\MailKitBundle\Tests\Support\GraphInboundFixtures;
use Nowo\MailKitBundle\Tests\Support\HostGraphMailboxProvider;
use Nowo\MailKitBundle\Tests\Support\InboundStoreTrait;
use Nowo\MailKitBundle\Tests\Support\RecordingInboundHandler;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Response\MockResponse;

use function print_r;
use function rawurldecode;
use function str_contains;
use function str_repeat;
use function strlen;

final class GraphInboundProcessorTest extends TestCase
{
    use InboundStoreTrait;

    private GraphApiMock $mock;

    private RecordingInboundHandler $handler;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->mock    = new GraphApiMock();
        $this->handler = new RecordingInboundHandler();
        $this->logger  = new RecordingLogger();
    }

    private function processor(?InboundLimits $limits = null): GraphInboundProcessor
    {
        $client = $this->mock->client();

        return new GraphInboundProcessor(
            new HostGraphMailboxProvider(),
            new GraphApiClient($client, new GraphTokenProvider($client, new ArrayAdapter())),
            $this->inboundStore(),
            $this->handler,
            new GraphMessageNormalizer(new InboundHtmlSanitizer()),
            $limits ?? new InboundLimits(),
            900,
            $this->logger,
        );
    }

    /**
     * @param list<mixed> $attachments
     * @param array<string, mixed> $overrides
     */
    private function deliver(string $id, array $attachments = [], array $overrides = []): void
    {
        $this->mock->inbox['projects@example.com'][$id] = GraphInboundFixtures::message($id, ['hasAttachments' => $attachments !== [], '_attachments' => $attachments] + $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function file(string $id, string $name, string $bytes, string $declared = 'application/pdf', ?int $size = null, bool $inline = false, ?string $contentId = null): array
    {
        return ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => $id, 'name' => $name, 'contentType' => $declared, 'size' => $size ?? strlen($bytes), 'isInline' => $inline, '_bytes' => $bytes] + ($contentId !== null ? ['contentId' => $contentId] : []);
    }

    public function testRoutesAMessageWithItsAttachmentsOnce(): void
    {
        $this->deliver('m1', [
            $this->file('a1', '../../offer.pdf', GraphInboundFixtures::PDF),
            $this->file('a2', 'invoice.pdf', GraphInboundFixtures::EXE),
            $this->file('a3', 'plan.png', GraphInboundFixtures::PNG, 'image/png', null, true, '<plan@client>'),
            ['@odata.type' => '#microsoft.graph.itemAttachment', 'id' => 'a4', 'name' => 'Original message', 'contentType' => null, 'size' => 900, 'isInline' => false],
            ['@odata.type' => '#microsoft.graph.referenceAttachment', 'id' => 'a5', 'name' => 'Cloud link', 'size' => 10],
            'garbage',
        ]);
        $processor = $this->processor();
        $decision  = $processor->process('acme', 'm1');

        self::assertSame(InboundAction::Route, $decision?->action);
        self::assertSame('acme:t-42', $decision->reference);
        $message = $this->handler->messages[0];
        self::assertTrue($message->forwarded, 'An attached e-mail marks the message as forwarded.');
        [$pdf, $exe, $png, $item, $reference] = $message->attachments;
        self::assertSame(['offer.pdf', 'application/pdf', true, 'offer.pdf'], [$pdf->filename, $pdf->contentType, $pdf->isAccepted(), $pdf->safeFilename]);
        self::assertSame([InboundAttachment::REJECTED_TYPE, 'application/pdf'], [$exe->rejection, $exe->declaredContentType]);
        self::assertNotSame('application/pdf', $exe->contentType);
        self::assertSame(['image/png', 'plan@client', true], [$png->contentType, $png->contentId, $png->inline]);
        self::assertSame(InboundAttachment::REJECTED_UNSUPPORTED, $item->rejection);
        self::assertSame(InboundAttachment::REJECTED_UNSUPPORTED, $reference->rejection);
        self::assertSame(['offer.pdf' => GraphInboundFixtures::PDF, 'plan.png' => GraphInboundFixtures::PNG], $this->handler->bytes[0]);

        // Streams are released when the message is done.
        try {
            $pdf->stream();
            self::fail('Released after processing.');
        } catch (LogicException) {
        }

        // The same message again (webhook + sweep): skipped.
        self::assertNull($processor->process('acme', 'm1'));
        self::assertCount(1, $this->handler->messages);

        // No subject, address or body in the logs.
        $logs = print_r($this->logger->records, true);
        foreach (['Offer', 'client@example.net', 'projects', 'Hello', 'offer.pdf', 'tok-'] as $needle) {
            self::assertStringNotContainsString($needle, $logs);
        }
        self::assertTrue($this->logger->has('already processed'));

        $read = $this->mock->matching('/messages/m1?$select=', 'GET')[0];
        self::assertStringContainsString('outlook.body-content-type="html"', $read['headers']['prefer']);
    }

    public function testLimits(): void
    {
        $this->deliver('m2', [
            $this->file('a1', 'a.pdf', GraphInboundFixtures::PDF),
            $this->file('a2', 'b.pdf', GraphInboundFixtures::PDF, size: 10_000),
            $this->file('a3', 'c.pdf', GraphInboundFixtures::PDF),
            $this->file('a4', 'd.pdf', GraphInboundFixtures::PDF),
            $this->file('a5', 'e.pdf', str_repeat('x', 300), size: 10),
        ]);
        $this->processor(new InboundLimits(maxAttachments: 3, maxAttachmentBytes: 1000, maxTotalAttachmentBytes: 100))->process('acme', 'm2');
        $rejections = [];
        foreach ($this->handler->messages[0]->attachments as $attachment) {
            $rejections[] = $attachment->rejection;
        }
        self::assertSame([null, InboundAttachment::REJECTED_SIZE, InboundAttachment::REJECTED_TOTAL_SIZE, InboundAttachment::REJECTED_COUNT, InboundAttachment::REJECTED_COUNT], $rejections);

        // The declared size lies: the download is capped.
        $this->handler->messages = [];
        $this->deliver('m3', [$this->file('a1', 'big.pdf', GraphInboundFixtures::PDF . str_repeat('x', 5000), size: 10)]);
        $this->processor(new InboundLimits(maxAttachmentBytes: 1000))->process('acme', 'm3');
        self::assertSame(InboundAttachment::REJECTED_SIZE, $this->handler->messages[0]->attachments[0]->rejection);

        // Inline parts: Content-ID from the metadata, bytes from $value; dangerous extensions are never downloaded;
        // a name whose extension disagrees with the detected type gets a safe name.
        $this->handler->messages = [];
        $this->deliver('m4', [
            self::file('a1', 'logo.png', GraphInboundFixtures::PNG, 'image/png', null, true, '<logo@client>'),
            self::file('a2', 'z.png', GraphInboundFixtures::PNG, 'image/png', null, true, 'bad cid'),
            self::file('a3', 'report.pdf.html', GraphInboundFixtures::PDF),
            self::file('a4', 'chart.svg', GraphInboundFixtures::PNG, 'image/png'),
            self::file('a5', 'photo.pdf', GraphInboundFixtures::PNG, 'application/pdf'),
            self::file('a6', 'noext', GraphInboundFixtures::PDF),
        ]);
        $this->processor()->process('acme', 'm4');
        [$logo, $badCid, $html, $svg, $photo, $noext] = $this->handler->messages[0]->attachments;
        self::assertSame(['logo@client', true, 'logo.png'], [$logo->contentId, $logo->isAccepted(), $logo->safeFilename]);
        self::assertTrue($badCid->isAccepted());
        self::assertNull($badCid->contentId);
        self::assertSame(InboundAttachment::REJECTED_EXTENSION, $html->rejection);
        self::assertSame(InboundAttachment::REJECTED_EXTENSION, $svg->rejection);
        self::assertCount(0, $this->mock->matching('/attachments/a3/$value'));
        self::assertSame(['image/png', 'photo.png'], [$photo->contentType, $photo->safeFilename]);
        self::assertSame('noext.pdf', $noext->safeFilename);
        self::assertCount(1, $this->mock->matching('/attachments/a1?$select=id,contentId,isInline'));

        // Over the message cap: handed over without body or headers, flagged oversized (no endless retry).
        $this->handler->messages = [];
        $this->deliver('m5', [self::file('a1', 'a.pdf', GraphInboundFixtures::PDF)], ['body' => ['contentType' => 'text', 'content' => str_repeat('a', 100_000)]]);
        $decision = $this->processor(new InboundLimits(maxMessageBytes: 65_536))->process('acme', 'm5');
        self::assertSame(InboundAction::Route, $decision?->action);
        $oversized = $this->handler->messages[0];
        self::assertTrue($oversized->oversized);
        self::assertSame('', $oversized->text);
        self::assertSame('Re: Offer for task 42', $oversized->subject);
        self::assertNull($oversized->inReplyTo);
        self::assertTrue($this->logger->has('over the size limit'));
        self::assertSame([], $oversized->attachments, 'Attachments are not read for an oversized message.');
        self::assertCount(0, $this->mock->matching('/messages/m5/attachments'));

        // Even the envelope is over its cap (huge recipient lists): read without the recipients.
        $this->handler->messages = [];
        $this->deliver('m6', [], ['body' => ['contentType' => 'text', 'content' => str_repeat('a', 100_000)]]);
        $this->mock->override = static fn (string $method, string $url): ?MockResponse => match (true) {
            !str_contains($url, '/messages/m6?') || str_contains(rawurldecode($url), 'body') => null,
            str_contains(rawurldecode($url), 'toRecipients')                                 => GraphApiMock::json(['id' => 'm6', 'toRecipients' => str_repeat('x', 300_000)]),
            default                                                                          => GraphApiMock::json(['id' => 'm6', 'internetMessageId' => '<m6@x>', 'subject' => 'Huge']),
        };
        $this->processor(new InboundLimits(maxMessageBytes: 65_536))->process('acme', 'm6');
        self::assertTrue($this->handler->messages[0]->oversized);
        self::assertSame([], $this->handler->messages[0]->to);
        self::assertCount(1, $this->mock->matching('/messages/m6?$select=' . GraphInboundProcessor::SELECT_MINIMAL));
        // Another error on the envelope read propagates.
        $this->mock->override = static fn (string $method, string $url): ?MockResponse => str_contains($url, '/messages/m7?')
            ? (str_contains(rawurldecode($url), 'body') ? GraphApiMock::json(['body' => str_repeat('b', 100_000)]) : GraphApiMock::json(['error' => ['code' => 'ErrorInternalServerError']], 500))
            : null;
        try {
            $this->processor(new InboundLimits(maxMessageBytes: 65_536))->process('acme', 'm7');
            self::fail('A 500 on the envelope must propagate.');
        } catch (GraphException $e) {
            self::assertSame(500, $e->status);
        }
        $this->mock->override = null;
    }

    public function testGoneInvalidAndFailingMessages(): void
    {
        $processor = $this->processor();
        self::assertNull($processor->process('acme', 'missing'));
        self::assertTrue($this->logger->has('gone before it was read'));
        self::assertNull($processor->process('acme', 'bad id!'));
        self::assertTrue($this->logger->has('message id refused'));

        // A host failure releases the claim: the next delivery processes the message.
        $this->deliver('m6');
        $this->handler->fail = true;
        try {
            $processor->process('acme', 'm6');
            self::fail('The host failure must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('Host storage unavailable.', $e->getMessage());
        }
        $this->handler->fail = false;
        self::assertSame(InboundAction::Route, $processor->process('acme', 'm6')?->action);

        // Without an internetMessageId the Graph id is the key; without a tag the host quarantines.
        $this->deliver('m7', [], ['internetMessageId' => null, 'toRecipients' => []]);
        self::assertSame(InboundAction::Quarantine, $processor->process('acme', 'm7')?->action);
        self::assertNull($processor->process('acme', 'm7'));

        // Graph errors other than 404 propagate (Messenger retries).
        $this->mock->override = static fn (string $method, string $url): ?MockResponse => str_contains($url, '/messages/m8') ? GraphApiMock::json(['error' => ['code' => 'ErrorInternalServerError']], 500) : null;
        try {
            $processor->process('acme', 'm8');
            self::fail('A 500 must propagate.');
        } catch (GraphException $e) {
            self::assertSame(500, $e->status);
        }

        // An attachment download failing for another reason propagates and releases the claim.
        $this->mock->override = static fn (string $method, string $url): ?MockResponse => str_contains($url, '/attachments/a1/$value') ? GraphApiMock::json(['error' => ['code' => 'ErrorInternalServerError']], 503) : null;
        $this->deliver('m9', [$this->file('a1', 'a.pdf', GraphInboundFixtures::PDF)]);
        try {
            $processor->process('acme', 'm9');
            self::fail('A 503 must propagate.');
        } catch (GraphException $e) {
            self::assertSame(GraphException::REASON_THROTTLED, $e->reason);
        }
        $this->mock->override = null;
        self::assertSame(InboundAction::Route, $processor->process('acme', 'm9')?->action);
    }
}
