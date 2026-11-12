<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Send;

use function ltrim;
use function mb_strlen;
use function mb_substr;
use function preg_replace;
use function rtrim;
use function str_replace;
use function strrpos;
use function substr;
use function trim;

/**
 * Neutralises attachment file names (1.8): no directory part (`/`, `\`), no control or bidi characters, no `:` (NTFS
 * alternate data streams; 1.9), no leading dots, no trailing dots or spaces (1.9),
 * at most 200 characters (extension kept), never empty. Applied to outgoing names and meant for inbound names too, so a
 * name can be used as a display label or a download name without a path traversal.
 */
final class AttachmentFilename
{
    /** Longest name kept. */
    public const MAX_LENGTH = 200;

    /** Name used when nothing is left. */
    public const FALLBACK = 'attachment';

    /**
     * The safe form of a file name.
     */
    public static function neutralise(?string $name): string
    {
        $name  = (string) $name;
        $name  = (string) preg_replace('/[\x00-\x1F\x7F\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name);
        $name  = str_replace('\\', '/', $name);
        $slash = strrpos($name, '/');
        if ($slash !== false) {
            $name = substr($name, $slash + 1);
        }
        $name = str_replace(':', '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        // Windows ignores trailing dots and spaces (`evil.exe.` runs as `evil.exe`); leading dots hide files.
        $name = trim(rtrim(ltrim($name, '.'), '. '));
        if ($name === '') {
            return self::FALLBACK;
        }
        if (mb_strlen($name) > self::MAX_LENGTH) {
            $dot       = strrpos($name, '.');
            $extension = $dot !== false && mb_strlen(substr($name, $dot)) <= 16 ? substr($name, $dot) : '';
            $name      = mb_substr($name, 0, self::MAX_LENGTH - mb_strlen($extension)) . $extension;
        }

        return $name;
    }
}
