<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use InvalidArgumentException;
use LogicException;
use Nowo\MailKitBundle\Graph\Send\AttachmentFilename;
use Nowo\MailKitBundle\Graph\Send\GraphAttachment;

use function assert;
use function basename;
use function fclose;
use function filesize;
use function fopen;
use function fseek;
use function fstat;
use function fwrite;
use function hash_final;
use function hash_init;
use function hash_update_stream;
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
 * An attachment of an {@see OutgoingMailboxMail} (2.5.0), provider-neutral and streamed (the 1.8 `GraphAttachment`
 * rules): a file path (opened only while it is uploaded), a stream or a string. The size is known up front (file size,
 * `fstat()`, or a non-seekable stream copied once to `php://temp` with a cap), so MailKit checks the limits before any
 * provider call and uploads large files in chunks read from the stream.
 *
 * The file name is neutralised; the content type must be a plain `type/subtype` token (else
 * `application/octet-stream`). An inline part needs a Content-ID that the HTML references (`cid:`). Not serialisable
 * (a stream cannot be queued).
 */
final class OutgoingAttachment
{
    /** Hard cap of one attachment: Microsoft Graph's upload session limit (150 MiB). */
    public const MAX_BYTES = 157_286_400;

    /** Neutralised file name. */
    public readonly string $filename;

    /** Declared content type (validated token). */
    public readonly string $contentType;

    /** Content-ID of an inline part, or null. */
    public readonly ?string $contentId;

    /**
     * @param resource|string $source path (`$isPath`) or stream
     *
     * @throws InvalidArgumentException on an invalid Content-ID, an inline part without one or a size over the cap
     */
    private function __construct(
        private readonly mixed $source,
        private readonly bool $isPath,
        ?string $filename,
        ?string $contentType,
        public readonly bool $inline,
        ?string $contentId,
        public readonly int $size,
    ) {
        $this->filename    = AttachmentFilename::neutralise($filename);
        $type              = strtolower(trim((string) $contentType));
        $this->contentType = preg_match('#^[a-z0-9][a-z0-9!\#$&^_.+-]{0,63}/[a-z0-9][a-z0-9!\#$&^_.+-]{0,126}\z#', $type) === 1 ? $type : 'application/octet-stream';
        if ($contentId !== null && preg_match(GraphAttachment::CONTENT_ID_PATTERN, $contentId) !== 1) {
            throw new InvalidArgumentException('Invalid attachment Content-ID.');
        }
        if ($inline && $contentId === null) {
            throw new InvalidArgumentException('An inline attachment needs a Content-ID.');
        }
        if ($size > self::MAX_BYTES) {
            throw new InvalidArgumentException('An attachment exceeds 150 MiB.');
        }
        $this->contentId = $contentId;
    }

    /**
     * Attachment read from a file when it is uploaded.
     *
     * @throws InvalidArgumentException when the file is not readable
     */
    public static function fromPath(string $path, ?string $filename = null, ?string $contentType = null, bool $inline = false, ?string $contentId = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('Attachment file not readable.');
        }
        $size = filesize($path);
        assert($size !== false);

        return new self($path, true, $filename ?? basename($path), $contentType, $inline, $contentId, $size);
    }

    /**
     * Attachment read from a stream. A non-seekable stream is copied to `php://temp` (at most `$maxBytes`; a longer one
     * is refused). The caller keeps ownership of the stream.
     *
     * @param resource $stream readable stream
     *
     * @throws InvalidArgumentException when it is not a stream or too long
     */
    public static function fromStream(mixed $stream, ?string $filename, ?string $contentType = null, bool $inline = false, ?string $contentId = null, int $maxBytes = self::MAX_BYTES): self
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Attachment stream is not a resource.');
        }
        $meta = stream_get_meta_data($stream);
        if (!$meta['seekable'] || fseek($stream, 0, SEEK_CUR) !== 0) {
            $copy = fopen('php://temp/maxmemory:1048576', 'w+');
            assert(is_resource($copy));
            $copied = stream_copy_to_stream($stream, $copy, $maxBytes + 1);
            if ($copied === false || $copied > $maxBytes) {
                fclose($copy);

                throw new InvalidArgumentException('Attachment stream exceeds the size limit.');
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
     * Opens the content for reading from the start. A file is opened per call; release it with {@see close()}.
     *
     * @throws InvalidArgumentException when the file can no longer be read
     *
     * @return resource
     */
    public function open(): mixed
    {
        if ($this->isPath) {
            $handle = @fopen((string) $this->source, 'r');
            if ($handle === false) {
                throw new InvalidArgumentException('Attachment file not readable.');
            }

            return $handle;
        }
        /** @var resource $stream */
        $stream = $this->source;
        rewind($stream);

        return $stream;
    }

    /**
     * SHA-256 of the content, streamed (never loaded whole): part of the message fingerprint, so a key reused with other
     * attachment bytes is refused (audit ZAV-I1).
     *
     * @throws InvalidArgumentException when the file can no longer be read
     */
    public function contentHash(): string
    {
        $handle  = $this->open();
        $context = hash_init('sha256');

        try {
            hash_update_stream($context, $handle);
        } finally {
            $this->close($handle);
        }

        return hash_final($context);
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
     * Serialization is refused (a stream cannot be queued).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('An outgoing mailbox attachment cannot be serialized.');
    }
}
