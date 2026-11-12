<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use function is_array;
use function is_string;
use function min;
use function stream_context_get_options;
use function strlen;
use function substr;

/**
 * Stream wrapper `mk-chunked://` that hands out at most 8 KiB per read, like php://input (audit NQ-M1 test). The
 * content comes from the stream context option `mk-chunked.content`.
 */
final class ChunkedStream
{
    /** @var resource|null */
    public $context;

    private string $content = '';

    private int $position = 0;

    public function stream_open(): bool
    {
        $context        = $this->context !== null ? stream_context_get_options($this->context) : [];
        $content        = is_array($context['mk-chunked'] ?? null) ? ($context['mk-chunked']['content'] ?? '') : '';
        $this->content  = is_string($content) ? $content : '';
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->content, $this->position, min($count, 8192));
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->content);
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
