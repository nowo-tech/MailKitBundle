<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph\Inbound;

use Nowo\MailKitBundle\Graph\Inbound\GraphInboundMessage;
use Nowo\MailKitBundle\Graph\Inbound\GraphMessageNormalizer;
use Nowo\MailKitBundle\Graph\Inbound\InboundHtmlSanitizer;
use Nowo\MailKitBundle\Tests\Support\GraphInboundFixtures;
use PHPUnit\Framework\TestCase;

use function range;

use const DATE_ATOM;

final class GraphMessageNormalizerTest extends TestCase
{
    /**
     * @param array<mixed> $data
     */
    private function normalize(array $data, bool $attachedMessage = false): GraphInboundMessage
    {
        return (new GraphMessageNormalizer(new InboundHtmlSanitizer()))->normalize('acme', 'projects@example.com', $data, [], $attachedMessage);
    }

    public function testFullMessage(): void
    {
        $message = $this->normalize(GraphInboundFixtures::message('m1'));
        self::assertSame('acme', $message->profile);
        self::assertSame('m1', $message->graphId);
        self::assertSame('reply-m1@client.example.net', $message->internetMessageId);
        self::assertSame('task-42-1@example.com', $message->inReplyTo);
        self::assertSame(['task-42-0@example.com', 'task-42-1@example.com'], $message->references);
        self::assertSame('AAQkConvm1', $message->conversationId);
        self::assertSame('client@example.net', $message->from?->address);
        self::assertSame('Client', $message->sender?->name);
        self::assertSame([], $message->replyTo);
        self::assertSame('t-42', $message->to[0]->tag);
        self::assertNull($message->cc[0]->tag);
        self::assertSame(['t-42'], $message->plusTags);
        self::assertSame('Re: Offer for task 42', $message->subject);
        self::assertStringNotContainsString('script', (string) $message->html);
        self::assertStringContainsString('cid:plan@client', (string) $message->html);
        self::assertSame('Hello team', $message->text);
        self::assertSame('2026-11-12T10:00:00+00:00', $message->receivedAt?->format(DATE_ATOM));
        self::assertSame(['pass', 'pass', 'pass'], [$message->authentication->spf, $message->authentication->dkim, $message->authentication->dmarc]);
        self::assertFalse($message->forwarded);
        self::assertSame([], $message->attachments);
    }

    public function testTextBodyEnvelopeTagsAndForwardSignals(): void
    {
        $data = GraphInboundFixtures::message('m2', [
            'subject'                => "[EXT] Fwd:\x01 Contract",
            'body'                   => ['contentType' => 'text', 'content' => "Plain\x07 text\n---------- Forwarded message ---------\nFrom: someone"],
            'toRecipients'           => [['emailAddress' => ['address' => 'colleague@example.com']], ['emailAddress' => ['address' => 'no-at-sign']], 'garbage', ['emailAddress' => 'x']],
            'internetMessageHeaders' => [
                ['name' => 'X-MS-Exchange-Organization-OriginalEnvelopeRecipients', 'value' => 'projects+t-7@example.com, other@example.com'],
                ['name' => 'Delivered-To', 'value' => '<projects+t-8@EXAMPLE.com>'],
                ['name' => 'Bad', 'value' => ['array']],
                'garbage',
            ],
            'receivedDateTime'  => 'not a date',
            'internetMessageId' => 'no-brackets@example.net',
            'conversationId'    => 'bad conversation id',
            'from'              => null,
        ]);
        $message = $this->normalize($data);
        self::assertSame('[EXT] Fwd: Contract', $message->subject);
        self::assertNull($message->html);
        self::assertStringStartsWith("Plain text\n", $message->text);
        self::assertCount(1, $message->to);
        self::assertSame(['t-8', 't-7'], $message->plusTags);
        self::assertTrue($message->forwarded);
        self::assertNull($message->receivedAt);
        self::assertSame('no-brackets@example.net', $message->internetMessageId);
        self::assertNull($message->conversationId);
        self::assertNull($message->from);
        self::assertNull($message->inReplyTo);
        self::assertSame([], $message->references);
        self::assertNull($message->authentication->spf);
    }

    public function testForwardedByHeaderOrAttachedMessageAndReferencesCap(): void
    {
        $references = '';
        foreach (range(1, 120) as $i) {
            $references .= '<r' . $i . '@example.net> ';
        }
        $data = GraphInboundFixtures::message('m3', [
            'subject'                => 'Contract',
            'body'                   => ['contentType' => 'html', 'content' => '<p>Hi</p>'],
            'internetMessageHeaders' => [['name' => 'References', 'value' => $references], ['name' => 'Resent-From', 'value' => 'a@example.net']],
            'receivedDateTime'       => null,
        ]);
        $message = $this->normalize($data);
        self::assertTrue($message->forwarded);
        self::assertCount(GraphMessageNormalizer::MAX_REFERENCES, $message->references);
        self::assertSame('r21@example.net', $message->references[0]);
        self::assertNull($message->receivedAt);

        $plain = $this->normalize(GraphInboundFixtures::message('m4', ['subject' => 'Contract', 'internetMessageHeaders' => []]), true);
        self::assertTrue($plain->forwarded);
        self::assertFalse($this->normalize(GraphInboundFixtures::message('m5', ['subject' => 'Contract', 'internetMessageHeaders' => null]))->forwarded);

        $empty = $this->normalize(['id' => 'x']);
        self::assertSame('', $empty->subject);
        self::assertSame('', $empty->text);
        self::assertSame('', $empty->internetMessageId);
    }
}
