<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use InvalidArgumentException;

use function fclose;
use function is_resource;

/**
 * One attachment read on demand (2.5.0): a rewound `php://temp` stream (at most the requested bytes), its size, the
 * content type detected from the bytes (finfo) and the neutralised file name. MailKit keeps nothing: the caller owns
 * the stream and closes it ({@see close()}).
 */
final readonly class FetchedAttachment
{
    /**
     * @param resource $stream readable, rewound stream
     * @param int $size bytes in the stream
     * @param string $contentType detected type (`application/octet-stream` when unknown)
     * @param string $filename neutralised name
     *
     * @throws InvalidArgumentException when `$stream` is not a stream
     */
    public function __construct(
        public mixed $stream,
        public int $size,
        public string $contentType,
        public string $filename,
    ) {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('A fetched attachment needs an open stream.');
        }
    }

    /**
     * Closes the stream (idempotent).
     */
    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
