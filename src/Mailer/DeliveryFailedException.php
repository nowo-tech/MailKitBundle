<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailer;

use Nowo\MailKitBundle\Log\ErrorScrubber;
use SensitiveParameter;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

use function bin2hex;
use function mb_substr;
use function preg_match;
use function random_bytes;
use function sprintf;
use function str_contains;
use function strrchr;
use function strtok;
use function substr;
use function trim;

/**
 * The exception MailKit throws (and stores / flashes) instead of the transport's own: a short, scrubbed message with a
 * correlation reference. Provider response bodies (HTTP API bridges) never reach the caller, the sent-mail log, the
 * flash or the Messenger failure transport — only the HTTP status; the scrubbed detail goes to the application log
 * under the same reference. The original exception is not kept as `previous` (it may carry credentials or bodies).
 */
final class DeliveryFailedException extends TransportException
{
    /** Longest transport message kept (SMTP answers). */
    public const PUBLIC_MAX = 300;

    /**
     * @param string $reference correlation id, also logged with the detail
     * @param string $detail scrubbed full message (logs only)
     */
    public function __construct(
        string $message,
        public readonly string $reference,
        public readonly string $detail,
    ) {
        parent::__construct($message);
    }

    /**
     * Wraps a transport failure.
     *
     * @param string ...$secrets credentials of the DSN in use
     */
    public static function from(Throwable $exception, #[SensitiveParameter] string ...$secrets): self
    {
        if ($exception instanceof self) {
            return $exception;
        }

        $reference = bin2hex(random_bytes(4));
        $detail    = ErrorScrubber::scrub($exception->getMessage(), ...$secrets);
        $first     = trim((string) strtok($detail, "\r\n"));

        if ($exception instanceof HttpTransportException) {
            $message = sprintf('The mail provider answered HTTP %d (reference %s).', $exception->getResponse()->getStatusCode(), $reference);
        } elseif ($exception instanceof TransportExceptionInterface && $first !== '' && !str_contains($first, '<') && preg_match('/Unable to send an email: /', $first) !== 1) {
            $message = sprintf('%s (reference %s)', mb_substr($first, 0, self::PUBLIC_MAX), $reference);
        } else {
            $message = sprintf('Mail delivery failed: %s (reference %s).', substr((string) strrchr('\\' . $exception::class, '\\'), 1), $reference);
        }

        return new self($message, $reference, $detail);
    }
}
