<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Send;

use LogicException;
use Nowo\MailKitBundle\Graph\GraphException;

use function assert;
use function basename;
use function fclose;
use function filesize;
use function fopen;
use function fseek;
use function fstat;
use function fwrite;
use function is_file;
use function is_readable;
use function is_resource;
use function preg_match;
use function rewind;
use function stream_copy_to_stream;
use function stream_get_meta_data;
use function strlen;
use function strtolower;
use function trim;

use const SEEK_CUR;

/**
 * An attachment of a {@see GraphOutgoingMail} (1.8), streamed: a file path (opened only while uploading), a stream or
 * a string. The size is known up front (file size, `fstat()`, or a non-seekable stream copied once to `php://temp`
 * with a cap), so the sender checks the limits before calling Graph and picks the upload path: a plain attachment
 * below 2.9 MB, an upload session (chunks of 3.75 MiB) from there.
 *
 * The file name is neutralised ({@see AttachmentFilename}); the content type must be a plain `type/subtype` token
 * (else `application/octet-stream`). Inline parts carry a Content-ID referenced by the HTML (`cid:`).
 */
final class GraphAttachment
{
    /** Default cap of a non-seekable stream copied to php://temp: the default attachment limit (35 MiB, audit NK-3). */
    public const MAX_SPOOL_BYTES = GraphSendLimits::DEFAULT_MAX_BYTES;

    /** Accepted Content-ID of an inline part (RFC 5322 dot-atom characters, at most 128). */
    public const CONTENT_ID_PATTERN = '/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.@-]{1,128}\z/';

    /** Neutralised file name. */
    public readonly string $filename;

    /** Declared content type (validated token). */
    public readonly string $contentType;

    /** Content-ID of an inline part, or null. */
    public readonly ?string $contentId;

    /** Size in bytes. */
    public readonly int $size;

    /**
     * @param resource|string $source path (`$isPath`), stream, or bytes
     */
    private function __construct(
        private readonly mixed $source,
        private readonly bool $isPath,
        ?string $filename,
        ?string $contentType,
        public readonly bool $inline,
        ?string $contentId,
        int $size,
    ) {
        $this->filename    = AttachmentFilename::neutralise($filename);
        $type              = strtolower(trim((string) $contentType));
        $this->contentType = preg_match('#^[a-z0-9][a-z0-9!\#$&^_.+-]{0,63}/[a-z0-9][a-z0-9!\#$&^_.+-]{0,126}\z#', $type) === 1 ? $type : 'application/octet-stream';
        if ($contentId !== null && preg_match(self::CONTENT_ID_PATTERN, $contentId) !== 1) {
            throw GraphException::invalidMessage('Invalid attachment Content-ID.');
        }
        if ($inline && $contentId === null) {
            throw GraphException::invalidMessage('An inline attachment needs a Content-ID.');
        }
        $this->contentId = $contentId;
        $this->size      = $size;
    }

    /**
     * Attachment read from a file when it is uploaded (never loaded whole from 2.9 MB).
     */
    public static function fromPath(string $path, ?string $filename = null, ?string $contentType = null, bool $inline = false, ?string $contentId = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw GraphException::invalidMessage('Attachment file not readable.');
        }
        $size = filesize($path);
        assert($size !== false);

        return new self($path, true, $filename ?? basename($path), $contentType, $inline, $contentId, $size);
    }

    /**
     * Attachment read from a stream. A non-seekable stream is copied to `php://temp` (at most `$maxBytes`; a longer
     * one is refused): pass your `graph.limits.max_attachment_bytes` when it differs from the 35 MiB default. The caller keeps ownership of the stream (and closes it after the send).
     *
     * @param resource $stream readable stream
     */
    public static function fromStream(mixed $stream, ?string $filename, ?string $contentType = null, bool $inline = false, ?string $contentId = null, int $maxBytes = self::MAX_SPOOL_BYTES): self
    {
        if (!is_resource($stream)) {
            throw GraphException::invalidMessage('Attachment stream is not a resource.');
        }
        $meta = stream_get_meta_data($stream);
        if (!$meta['seekable'] || fseek($stream, 0, SEEK_CUR) !== 0) {
            $copy = fopen('php://temp/maxmemory:1048576', 'w+');
            assert(is_resource($copy));
            $copied = stream_copy_to_stream($stream, $copy, $maxBytes + 1);
            if ($copied === false || $copied > $maxBytes) {
                fclose($copy);

                throw GraphException::invalidMessage('Attachment stream exceeds the size limit.');
            }
            $stream = $copy;
        }
        rewind($stream);
        $stat = fstat($stream);
        assert($stat !== false);

        return new self($stream, false, $filename, $contentType, $inline, $contentId, $stat['size']);
    }

    /**
     * Attachment from bytes held in memory.
     */
    public static function fromString(string $bytes, ?string $filename, ?string $contentType = null, bool $inline = false, ?string $contentId = null): self
    {
        $stream = fopen('php://temp/maxmemory:1048576', 'w+');
        assert(is_resource($stream));
        fwrite($stream, $bytes);
        rewind($stream);

        return new self($stream, false, $filename, $contentType, $inline, $contentId, strlen($bytes));
    }

    /**
     * Opens the content for reading from the start. A file is opened per call; close it with {@see close()}.
     *
     * @return resource
     */
    public function open(): mixed
    {
        if ($this->isPath) {
            $handle = @fopen((string) $this->source, 'r');
            if ($handle === false) {
                throw GraphException::invalidMessage('Attachment file not readable.');
            }

            return $handle;
        }
        /** @var resource $stream */
        $stream = $this->source;
        rewind($stream);

        return $stream;
    }

    /**
     * Releases a handle returned by {@see open()} (closes files; streams stay with their owner).
     *
     * @param resource $handle
     */
    public function close(mixed $handle): void
    {
        if ($this->isPath && is_resource($handle)) {
            fclose($handle);
        }
    }

    /**
     * Serialization is refused (streams cannot be queued; queue the e-mail through the Mailer transport instead).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A Graph attachment cannot be serialized; send through the microsoft+graph mailer transport to queue e-mails.');
    }
}
