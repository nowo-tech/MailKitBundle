<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * Runs a callback and returns the `E_USER_DEPRECATED` messages it triggered (1.14 notice tests), so a test can assert
 * the exact notices and also that none fired. Other errors go to the previous handler.
 */
final class DeprecationRecorder
{
    /**
     * @param callable(): mixed $callback
     *
     * @return list<string>
     */
    public static function record(callable $callback): array
    {
        $messages = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test helper, restored in the finally block)
        set_error_handler(static function (int $type, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $callback();
        } finally {
            restore_error_handler();
        }

        return $messages;
    }
}
