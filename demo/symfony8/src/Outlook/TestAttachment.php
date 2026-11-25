<?php

declare(strict_types=1);

namespace App\Outlook;

use function hash;
use function intdiv;
use function substr;

/**
 * DEMO ONLY (Outlook manual test harness): deterministic attachment bytes derived from the idempotency key, so a
 * re-run with the same key builds the same message (same fingerprint → `replayed: true`, never a key conflict).
 */
final class TestAttachment
{
    public static function bytes(string $key, int $size): string
    {
        $bytes = '';
        $count = intdiv($size, 64) + 1;
        for ($i = 0; $i < $count; ++$i) {
            $bytes .= hash('sha512', $key . ':' . $i, true);
        }

        return substr($bytes, 0, $size);
    }
}
