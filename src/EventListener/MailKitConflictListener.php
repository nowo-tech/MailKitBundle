<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\EventListener;

use Nowo\MailKitBundle\Concurrency\StorageConflicts;
use Nowo\MailKitBundle\Controller\MailKitController;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

use function is_string;
use function str_starts_with;

/**
 * Since 1.13.0 (REQ-RUNTIME-003 rule 6): a storage conflict on one of the bundle's own routes (admin pages and
 * actions, Graph webhook, tracking pixel) answers the platform conflict answer instead of a 500.
 *
 * The answer is **409** (`conflict_response.status: 503` for hosts that want it) with `X-Conflict-Code`:
 * `CONFLICT_001` and `Retry-After` when nothing was committed, `CONFLICT_002` and no `Retry-After` when part of the
 * request was ({@see StorageConflicts::code()}: request commit mark of transaction-doctrine >= 1.6, a Messenger
 * handler that already completed, a webhook batch with notifications already queued). Admin routes become an
 * HttpException with that status and those headers, rendered by your error page; the webhook and the pixel get a
 * `text/plain` answer.
 *
 * Not conflicts, never mapped: unique violations and lost connections (they stay real errors). Host routes are never
 * answered (the `_controller` must be one of the bundle's). Priority -64: after the application's own exception
 * listeners, before the framework's error listener (-128). Stateless.
 */
final readonly class MailKitConflictListener
{
    /** Generic, client-safe text when nothing was committed. */
    public const MESSAGE = 'The same data was being changed at the same time. Try again.';

    /** Generic, client-safe text when part of the request was committed. */
    public const COMMITTED_MESSAGE = 'Part of the request was saved before a conflict. Reload before trying again.';

    private const CONTROLLER_PREFIX = 'Nowo\\MailKitBundle\\';

    /**
     * @param ConflictResponsePolicy $policy status (409 or 503) and Retry-After (`conflict_response`)
     */
    public function __construct(
        private ConflictResponsePolicy $policy = new ConflictResponsePolicy(),
    ) {
    }

    /**
     * Answers a storage conflict of a bundle route.
     */
    public function __invoke(ExceptionEvent $event): void
    {
        $request    = $event->getRequest();
        $controller = $request->attributes->get('_controller');
        if (!is_string($controller) || !str_starts_with($controller, self::CONTROLLER_PREFIX)) {
            return;
        }
        $failure = $event->getThrowable();
        if (!StorageConflicts::isConflict($failure)) {
            return;
        }
        $code    = StorageConflicts::code($request, $failure);
        $hint    = StorageConflicts::retryAfterHint($failure);
        $message = $code === ConflictCode::Committed ? self::COMMITTED_MESSAGE : self::MESSAGE;
        if (str_starts_with($controller, MailKitController::class)) {
            $event->setThrowable($this->policy->httpExceptionFor($code, $message, $failure, $hint));

            return;
        }

        $event->setResponse(new Response($message, $this->policy->status, $this->policy->headersFor($code, $hint) + [
            'Content-Type'           => 'text/plain; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'no-store, private',
        ]));
    }
}
