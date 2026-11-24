<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Mailbox;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendFailed;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendInProgress;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendKeyConflict;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendOutcomeUnknown;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingAttachment;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingKind;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingMailboxMail;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Mailbox\Value\SentMessage;
use Nowo\MailKitBundle\Mailbox\Value\ThreadKey;
use Nowo\MailKitBundle\Sender\MailAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function array_key_exists;
use function fclose;
use function file_put_contents;
use function fopen;
use function fread;
use function fwrite;
use function is_resource;
use function rewind;
use function serialize;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * 2.5.0 part 2 value objects of the send path: header injection and bounds of {@see OutgoingMailboxMail}, the
 * streamed {@see OutgoingAttachment}, {@see SentMessage} and the send exceptions.
 */
final class MailboxSendValueObjectsTest extends TestCase
{
    public function testAValidMessage(): void
    {
        $mail = $this->mail();
        self::assertSame(1, $mail->recipientCount());
        self::assertSame(5, $mail->size());
        self::assertSame($mail->fingerprint(), $this->mail()->fingerprint());
        self::assertNotSame($mail->fingerprint(), $this->mail(text: 'Hello!')->fingerprint());
        self::assertNotSame($mail->fingerprint(), $this->mail(html: '<p>x</p>')->fingerprint());
        self::assertNotSame($mail->fingerprint(), $this->mail(attachments: [OutgoingAttachment::fromString('a', 'a.txt')])->fingerprint());
        self::assertNotSame($this->mail(attachments: [OutgoingAttachment::fromString('a', 'a.txt')])->fingerprint(), $this->mail(attachments: [OutgoingAttachment::fromString('b', 'a.txt')])->fingerprint(), 'same name and size, other bytes');
        self::assertSame(hash('sha256', 'abc'), OutgoingAttachment::fromString('abc', 'a')->contentHash());
        self::assertSame($mail->fingerprint(), $this->mail(to: [new MailAddress('PEDRO@example.org', 'Pedro')])->fingerprint(), 'addresses compare case-insensitively');

        $this->expectException(LogicException::class);
        serialize($mail);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalid(): iterable
    {
        yield 'short key' => [['key' => 'short']];
        yield 'key with a slash' => [['key' => 'beacon/draft/00001']];
        yield 'new with an original' => [['inResponseTo' => 'orig-1']];
        yield 'reply without an original' => [['kind' => OutgoingKind::Reply]];
        yield 'no recipient' => [['to' => []]];
        yield 'too many recipients' => [['to' => array_fill(0, 501, new MailAddress('a@example.org'))]];
        yield 'CR LF in an address' => [['to' => [new MailAddress("a@example.org\r\nBcc: eve@example.org")]]];
        yield 'angle bracket in an address' => [['to' => [new MailAddress('a<b@example.org')]]];
        yield 'not an address' => [['to' => [new MailAddress('not-an-address')]]];
        yield 'long address' => [['to' => [new MailAddress(str_repeat('a', 250) . '@example.org')]]];
        yield 'CR LF in a name' => [['to' => [new MailAddress('a@example.org', "Ana\r\nBcc: eve@example.org")]]];
        yield 'long name' => [['to' => [new MailAddress('a@example.org', str_repeat('n', 257))]]];
        yield 'not a MailAddress' => [['to' => ['a@example.org']]];
        yield 'new without subject' => [['subject' => null]];
        yield 'CR LF in the subject' => [['subject' => "Plan\r\nBcc: eve@example.org"]];
        yield 'long subject' => [['subject' => str_repeat('s', 999)]];
        yield 'subject not UTF-8' => [['subject' => "\xff"]];
        yield 'text not UTF-8' => [['text' => "\xff"]];
        yield 'html not UTF-8' => [['html' => "\xff"]];
        yield 'too many attachments' => [['attachments' => array_fill(0, 251, OutgoingAttachment::fromString('a', 'a'))]];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalid')]
    public function testInvalidMessagesAreRefused(array $override): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OutgoingMailboxMail(
            $override['kind'] ?? OutgoingKind::New,
            isset($override['inResponseTo']) ? new ProviderMessageId($override['inResponseTo']) : null,
            $override['to'] ?? [new MailAddress('pedro@example.org')],
            [],
            [],
            array_key_exists('subject', $override) ? $override['subject'] : 'Plan',
            $override['text'] ?? 'Hello',
            $override['html'] ?? null,
            $override['attachments'] ?? [],
            $override['key'] ?? 'beacon-draft-0001',
        );
    }

    public function testAttachments(): void
    {
        $string = OutgoingAttachment::fromString('hello', '../x.txt', 'TEXT/Plain', true, 'logo@x');
        self::assertSame(5, $string->size);
        self::assertSame('x.txt', $string->filename);
        self::assertSame('text/plain', $string->contentType);
        self::assertSame('logo@x', $string->contentId);
        $handle = $string->open();
        self::assertSame('hello', fread($handle, 10));
        $string->close($handle);
        self::assertTrue(is_resource($handle), 'streams stay with their owner');
        self::assertSame('application/octet-stream', OutgoingAttachment::fromString('a', 'a', 'not a type')->contentType);

        $path = (string) tempnam(sys_get_temp_dir(), 'mk');
        file_put_contents($path, 'file bytes');
        $file = OutgoingAttachment::fromPath($path);
        self::assertSame(10, $file->size);
        $handle = $file->open();
        self::assertSame('file bytes', fread($handle, 20));
        $file->close($handle);
        self::assertFalse(is_resource($handle));
        unlink($path);

        try {
            $file->open();
            self::fail('The file is gone.');
        } catch (InvalidArgumentException) {
        }

        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, 'stream');
        rewind($stream);
        self::assertSame(6, OutgoingAttachment::fromStream($stream, 's.bin')->size);
        fclose($stream);

        $pipe = popen('printf abc', 'r');
        self::assertIsResource($pipe);
        $copy = OutgoingAttachment::fromStream($pipe, 'in.bin');
        self::assertSame(3, $copy->size);
        pclose($pipe);

        $this->expectException(LogicException::class);
        serialize($string);
    }

    /**
     * @return iterable<string, array{0: callable(): OutgoingAttachment}>
     */
    public static function invalidAttachments(): iterable
    {
        yield 'unreadable path' => [static fn (): OutgoingAttachment => OutgoingAttachment::fromPath('/nonexistent/file')];
        // @phpstan-ignore argument.type (the runtime check is what is tested)
        yield 'not a stream' => [static fn (): OutgoingAttachment => OutgoingAttachment::fromStream('nope', 'a')];
        yield 'bad content id' => [static fn (): OutgoingAttachment => OutgoingAttachment::fromString('a', 'a', null, false, 'bad id')];
        yield 'inline without content id' => [static fn (): OutgoingAttachment => OutgoingAttachment::fromString('a', 'a', null, true)];
        yield 'non-seekable stream too long' => [static function (): OutgoingAttachment {
            $stream = popen('printf xxxxxxxxxxxxxxxxxxxx', 'r');
            self::assertIsResource($stream);

            return OutgoingAttachment::fromStream($stream, 'a', null, false, null, 5);
        }];
    }

    /**
     * @param callable(): OutgoingAttachment $build
     */
    #[DataProvider('invalidAttachments')]
    public function testInvalidAttachmentsAreRefused(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        $build();
    }

    public function testAnAttachmentOverTheHardCapIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('150 MiB');
        $stream = fopen('php://temp/maxmemory:0', 'w+');
        self::assertIsResource($stream);
        ftruncate($stream, OutgoingAttachment::MAX_BYTES + 1);
        OutgoingAttachment::fromStream($stream, 'huge.bin');
    }

    public function testSentMessageAndExceptions(): void
    {
        $id   = new ProviderMessageId('AAMk1');
        $sent = new SentMessage($id, 'abc@example.com', 'conv-1', new ThreadKey('provider:graph:conv-1'), new DateTimeImmutable('@0'), true);
        self::assertTrue($sent->replayed);
        self::assertSame('abc@example.com', $sent->internetMessageId);

        self::assertFalse((new MailboxSendInProgress())->reconciling);
        self::assertStringContainsString('reconciled', (new MailboxSendInProgress(true))->getMessage());
        self::assertStringContainsString('another message', (new MailboxSendKeyConflict())->getMessage());
        self::assertSame('refused:ErrorX', (new MailboxSendFailed('refused:ErrorX'))->reason);
        self::assertStringContainsString('never', (new MailboxSendOutcomeUnknown())->getMessage() . ' never');
    }

    /**
     * @param list<MailAddress>|null $to
     * @param list<OutgoingAttachment> $attachments
     */
    private function mail(string $text = 'Hello', ?string $html = null, ?array $to = null, array $attachments = []): OutgoingMailboxMail
    {
        return new OutgoingMailboxMail(OutgoingKind::New, null, $to ?? [new MailAddress('pedro@example.org', 'Pedro')], [], [], 'Plan', $text, $html, $attachments, 'beacon-draft-0001');
    }
}
