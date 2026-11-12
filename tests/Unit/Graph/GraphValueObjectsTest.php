<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph;

use LogicException;
use Nowo\MailKitBundle\Graph\ConfiguredGraphMailboxProvider;
use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\GraphMailbox;
use Nowo\MailKitBundle\Graph\GraphSecret;
use Nowo\MailKitBundle\Graph\Send\AttachmentFilename;
use Nowo\MailKitBundle\Graph\Send\GraphAttachment;
use Nowo\MailKitBundle\Graph\Send\GraphOutgoingMail;
use Nowo\MailKitBundle\Graph\Send\GraphRecipient;
use Nowo\MailKitBundle\Graph\Send\GraphSendResult;
use Nowo\MailKitBundle\Graph\Send\PlusAddress;
use Nowo\MailKitBundle\Tests\Support\GraphApiMock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function fclose;
use function file_put_contents;
use function fopen;
use function fwrite;
use function is_resource;
use function pclose;
use function popen;
use function print_r;
use function range;
use function serialize;
use function sprintf;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function unserialize;
use function var_export;

final class GraphValueObjectsTest extends TestCase
{
    public function testSecretIsNeverPrintedOrSerialized(): void
    {
        $secret = new GraphSecret(GraphApiMock::secret());
        self::assertSame(GraphApiMock::secret(), $secret->reveal());
        self::assertSame(64, strlen($secret->fingerprint()));
        self::assertStringNotContainsString(GraphApiMock::secret(), print_r($secret, true));
        self::assertSame(['value' => '[redacted]'], $secret->__debugInfo());

        try {
            serialize($secret);
            self::fail('Serialization must be refused.');
        } catch (LogicException $e) {
            self::assertStringNotContainsString(GraphApiMock::secret(), $e->getMessage());
        }
        $this->expectException(LogicException::class);
        unserialize(sprintf('O:%d:"%s":1:{s:5:"value";s:1:"x";}', strlen(GraphSecret::class), GraphSecret::class));
    }

    public function testEmptySecretIsRefused(): void
    {
        $this->expectException(GraphException::class);
        new GraphSecret('  ');
    }

    public function testMailboxValidatesAndRedacts(): void
    {
        $mailbox = new GraphMailbox('acme', 'contoso.example', GraphApiMock::CLIENT_A, new GraphSecret(GraphApiMock::secret()), ' Projects@Example.com ');
        self::assertSame('projects@example.com', $mailbox->address);
        self::assertSame('contoso.example', $mailbox->tenantId);
        $debug = print_r($mailbox, true);
        self::assertStringNotContainsString(GraphApiMock::secret(), $debug);
        self::assertStringContainsString('[redacted]', $debug);
        self::assertSame(GraphApiMock::TENANT_A, GraphApiMock::mailboxA()->tenantId);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function invalidMailboxes(): iterable
    {
        yield 'profile' => ['a/b', GraphApiMock::TENANT_A, GraphApiMock::CLIENT_A, 'a@example.com'];
        yield 'tenant path' => ['p', '../common', GraphApiMock::CLIENT_A, 'a@example.com'];
        yield 'client' => ['p', GraphApiMock::TENANT_A, 'not-a-guid', 'a@example.com'];
        yield 'address' => ['p', GraphApiMock::TENANT_A, GraphApiMock::CLIENT_A, 'not an address'];
        yield 'long address' => ['p', GraphApiMock::TENANT_A, GraphApiMock::CLIENT_A, str_repeat('a', 250) . '@example.com'];
    }

    #[DataProvider('invalidMailboxes')]
    public function testInvalidMailboxIsRefused(string $profile, string $tenant, string $client, string $address): void
    {
        try {
            new GraphMailbox($profile, $tenant, $client, new GraphSecret(GraphApiMock::secret()), $address);
            self::fail('Expected a configuration error.');
        } catch (GraphException $e) {
            self::assertSame(GraphException::REASON_CONFIGURATION, $e->reason);
            self::assertStringNotContainsString(GraphApiMock::secret(), $e->getMessage());
        }
    }

    public function testConfiguredProvider(): void
    {
        $provider = new ConfiguredGraphMailboxProvider(['acme' => [
            'tenant_id'     => GraphApiMock::TENANT_A,
            'client_id'     => GraphApiMock::CLIENT_A,
            'client_secret' => GraphApiMock::secret(),
            'mailbox'       => 'projects@example.com',
        ]], 'acme');
        self::assertSame('acme', $provider->defaultProfile());
        self::assertSame('projects@example.com', $provider->mailbox('acme')->address);
        self::assertSame(['profiles' => ['acme'], 'defaultProfile' => 'acme'], $provider->__debugInfo());
        self::assertStringNotContainsString(GraphApiMock::secret(), print_r($provider, true));

        try {
            $provider->mailbox('other');
            self::fail('Unknown profile.');
        } catch (GraphException $e) {
            self::assertStringContainsString('"other"', $e->getMessage());
        }
        $this->expectExceptionMessage('"?"');
        $provider->mailbox("bad\nname");
    }

    public function testExceptionFactories(): void
    {
        $status = GraphException::status('send', 403, 'ErrorAccessDenied', 'req-1');
        self::assertSame('Microsoft Graph send failed with HTTP 403 (ErrorAccessDenied), request-id req-1.', $status->getMessage());
        self::assertSame(GraphException::REASON_API, $status->reason);
        self::assertFalse($status->isRetryable());
        self::assertNull($status->getPrevious());

        $throttled = GraphException::status('send', 429, null, 'free text with spaces', 7);
        self::assertSame(GraphException::REASON_THROTTLED, $throttled->reason);
        self::assertSame(7, $throttled->retryAfter);
        self::assertNull($throttled->requestId);
        self::assertTrue($throttled->isRetryable());
        self::assertSame('Microsoft Graph send failed with HTTP 429.', $throttled->getMessage());

        self::assertSame(GraphException::REASON_AUTHENTICATION, GraphException::status('token request', 401, 'invalid_client', null)->reason);
        self::assertNull(GraphException::status('x', 500, null, null, 9)->retryAfter);
        self::assertTrue(GraphException::status('x', 500, null, null)->isRetryable());
        self::assertTrue(GraphException::transport('x')->isRetryable());
        self::assertFalse(GraphException::configuration('x')->isRetryable());
        self::assertFalse(GraphException::invalidMessage('x')->isRetryable());
        self::assertSame(GraphException::REASON_UNTRUSTED_URL, GraphException::untrustedUrl('x')->reason);
        self::assertFalse((new GraphException('x', GraphException::REASON_API))->isRetryable());
        self::assertNull(GraphException::safeToken(null));
        self::assertNull(GraphException::safeToken(str_repeat('a', 81)));
    }

    public function testRecipient(): void
    {
        $recipient = new GraphRecipient(' a+b@example.com ', " Ann\r\nBcc: x ");
        self::assertSame('a+b@example.com', $recipient->address);
        self::assertSame('Ann Bcc: x', $recipient->name);
        self::assertNull((new GraphRecipient('a@example.com', ' '))->name);
        self::assertNull((new GraphRecipient('a@example.com'))->name);

        foreach (['', 'a b@example.com', '<a@example.com>', 'nope', str_repeat('a', 250) . '@example.com'] as $bad) {
            try {
                new GraphRecipient($bad);
                self::fail('Invalid address accepted: ' . $bad);
            } catch (GraphException $e) {
                self::assertSame(GraphException::REASON_INVALID_MESSAGE, $e->reason);
            }
        }
    }

    public function testFilenameIsNeutralised(): void
    {
        self::assertSame('passwd', AttachmentFilename::neutralise('../../etc/passwd'));
        self::assertSame('evil.exe', AttachmentFilename::neutralise('C:\\dir\\evil.exe'));
        self::assertSame('htaccess', AttachmentFilename::neutralise('..htaccess'));
        self::assertSame('report.pdf', AttachmentFilename::neutralise("re\u{202E}port\x00.pdf"));
        self::assertSame('a b.txt', AttachmentFilename::neutralise("a \t b.txt"));
        self::assertSame(AttachmentFilename::FALLBACK, AttachmentFilename::neutralise(null));
        self::assertSame(AttachmentFilename::FALLBACK, AttachmentFilename::neutralise('dir/'));
        self::assertSame(AttachmentFilename::FALLBACK, AttachmentFilename::neutralise("\xff\xfe"));
        $long = AttachmentFilename::neutralise(str_repeat('x', 300) . '.pdf');
        self::assertSame(AttachmentFilename::MAX_LENGTH, mb_strlen($long));
        self::assertStringEndsWith('.pdf', $long);
        self::assertSame(AttachmentFilename::MAX_LENGTH, mb_strlen(AttachmentFilename::neutralise(str_repeat('y', 300))));
    }

    public function testAttachmentSources(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'mk-graph');
        file_put_contents($path, 'file-bytes');
        $file = GraphAttachment::fromPath($path, null, 'Application/PDF');
        self::assertSame(10, $file->size);
        self::assertSame('application/pdf', $file->contentType);
        self::assertSame(basename($path), $file->filename);
        $handle = $file->open();
        self::assertSame('file-bytes', stream_get_contents($handle));
        $file->close($handle);
        self::assertFalse(is_resource($handle));
        unlink($path);
        try {
            $file->open();
            self::fail('A removed file must not open.');
        } catch (GraphException $e) {
            self::assertSame('Attachment file not readable.', $e->getMessage());
        }
        try {
            GraphAttachment::fromPath($path);
            self::fail('Missing file.');
        } catch (GraphException $e) {
            self::assertSame(GraphException::REASON_INVALID_MESSAGE, $e->reason);
        }

        $string = GraphAttachment::fromString('abc', '../x.txt', 'text/plain; charset=utf-8');
        self::assertSame('x.txt', $string->filename);
        self::assertSame('application/octet-stream', $string->contentType);
        self::assertSame(3, $string->size);
        $handle = $string->open();
        self::assertSame('abc', stream_get_contents($handle));
        $string->close($handle);
        self::assertTrue(is_resource($handle));

        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, 'streamed');
        $seekable = GraphAttachment::fromStream($stream, 'logo.png', 'image/png', true, 'logo@x');
        self::assertSame(8, $seekable->size);
        self::assertTrue($seekable->inline);
        self::assertSame('logo@x', $seekable->contentId);
        fclose($stream);

        $pipe = popen('printf piped', 'r');
        self::assertIsResource($pipe);
        $spooled = GraphAttachment::fromStream($pipe, 'p.txt');
        pclose($pipe);
        self::assertSame(5, $spooled->size);
        self::assertSame('piped', stream_get_contents($spooled->open()));

        $pipe = popen('printf toolong', 'r');
        self::assertIsResource($pipe);
        try {
            GraphAttachment::fromStream($pipe, 'p.txt', null, false, null, 3);
            self::fail('Over the spool cap.');
        } catch (GraphException $e) {
            self::assertSame('Attachment stream exceeds the size limit.', $e->getMessage());
        } finally {
            pclose($pipe);
        }
    }

    public function testAttachmentRules(): void
    {
        foreach ([[true, null], [false, 'bad cid'], [false, str_repeat('a', 129)]] as [$inline, $cid]) {
            try {
                GraphAttachment::fromString('a', 'a', null, $inline, $cid);
                self::fail('Invalid inline / Content-ID accepted.');
            } catch (GraphException $e) {
                self::assertSame(GraphException::REASON_INVALID_MESSAGE, $e->reason);
            }
        }
        try {
            GraphAttachment::fromStream('not a stream', 'a'); // @phpstan-ignore argument.type
            self::fail('Not a resource.');
        } catch (GraphException $e) {
            self::assertSame('Attachment stream is not a resource.', $e->getMessage());
        }
        $this->expectException(LogicException::class);
        serialize(GraphAttachment::fromString('a', 'a'));
    }

    public function testOutgoingMail(): void
    {
        $references = [];
        foreach (range(1, 60) as $i) {
            $references[] = '<ref' . $i . '@example.com>';
        }
        $mail = new GraphOutgoingMail(
            to: [new GraphRecipient('a@example.com')],
            subject: "Hello\r\nBcc: injected",
            text: 'Hi',
            messageId: '<own@example.com>',
            inReplyTo: 'parent@example.com',
            references: $references,
        );
        self::assertSame('Hello Bcc: injected', $mail->subject);
        self::assertSame('own@example.com', $mail->messageId);
        self::assertSame('parent@example.com', $mail->inReplyTo);
        self::assertCount(GraphOutgoingMail::MAX_REFERENCES, $mail->references);
        self::assertSame('ref11@example.com', $mail->references[0]);
        self::assertNull($mail->html);

        $bccOnly = new GraphOutgoingMail([], 's', null, '<p>x</p>', bcc: [new GraphRecipient('b@example.com')]);
        self::assertCount(1, $bccOnly->bcc);

        $cases = [
            static fn (): GraphOutgoingMail => new GraphOutgoingMail([], 's', 'x'),
            static fn (): GraphOutgoingMail => new GraphOutgoingMail([new GraphRecipient('a@example.com')], 's'),
            static fn (): GraphOutgoingMail => new GraphOutgoingMail(['a@example.com'], 's', 'x'), // @phpstan-ignore argument.type
            static fn (): GraphOutgoingMail => new GraphOutgoingMail([new GraphRecipient('a@example.com')], 's', 'x', attachments: ['x']), // @phpstan-ignore argument.type
            static fn (): GraphOutgoingMail => new GraphOutgoingMail([new GraphRecipient('a@example.com')], 's', 'x', messageId: 'no-at-sign'),
            static fn (): GraphOutgoingMail => new GraphOutgoingMail([new GraphRecipient('a@example.com')], 's', 'x', inReplyTo: "a@b\r\nX: y"),
            static fn (): GraphOutgoingMail => new GraphOutgoingMail([new GraphRecipient('a@example.com')], 's', 'x', references: ['a b@c']),
            static fn (): GraphOutgoingMail => new GraphOutgoingMail([new GraphRecipient('a@example.com')], 's', 'x', messageId: str_repeat('a', 200) . '@' . str_repeat('b', 60)),
        ];
        foreach ($cases as $i => $case) {
            try {
                $case();
                self::fail('Case ' . var_export($i, true) . ' accepted.');
            } catch (GraphException $e) {
                self::assertSame(GraphException::REASON_INVALID_MESSAGE, $e->reason);
            }
        }
    }

    public function testPlusAddress(): void
    {
        self::assertSame('projects+t-42@example.com', PlusAddress::compose('projects@example.com', 't-42'));
        self::assertSame('projects+t-43@example.com', PlusAddress::compose('projects+old@example.com', 't-43'));
        self::assertSame('t-42', PlusAddress::tag('Projects+t-42@EXAMPLE.com', 'projects@example.com'));
        self::assertNull(PlusAddress::tag('projects@example.com', 'projects@example.com'));
        self::assertNull(PlusAddress::tag('other+t-42@example.com', 'projects@example.com'));
        self::assertNull(PlusAddress::tag('projects+t-42@example.org', 'projects@example.com'));
        self::assertNull(PlusAddress::tag('projects+bad tag@example.com', 'projects@example.com'));
        self::assertNull(PlusAddress::tag('broken', 'projects@example.com'));
        self::assertNull(PlusAddress::tag('a@b@c', 'projects@example.com'));
        foreach ([['projects@example.com', 'bad tag'], ['@example.com', 't'], ['+x@example.com', 't'], ['nope', 't']] as [$mailbox, $tag]) {
            try {
                PlusAddress::compose($mailbox, $tag);
                self::fail('Accepted ' . $mailbox . ' / ' . $tag);
            } catch (GraphException $e) {
                self::assertSame(GraphException::REASON_INVALID_MESSAGE, $e->reason);
            }
        }
    }

    public function testSendResult(): void
    {
        $result = new GraphSendResult('id', '<m@x>', null, 'acme');
        self::assertSame('id', $result->graphId);
        self::assertSame('<m@x>', $result->internetMessageId);
        self::assertNull($result->conversationId);
        self::assertSame('acme', $result->profile);
    }
}
