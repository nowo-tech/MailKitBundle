<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\EventListener;

use Nowo\MailKitBundle\Settings\LegacyResendSettings;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Forgets the {@see LegacyResendSettings} memo (the MailKit 1.2 `resend` rows) at every unit-of-work boundary, so a
 * request, Messenger message or console command never sends with rows read before another worker migrated, purged or
 * edited them, even when the services resetter does not run (a host that removes or bypasses services_resetter,
 * `messenger:consume --no-reset`, long-running console processes):
 *
 * - HTTP: at the start of every main request (sub-requests keep the memo of their main request) and at
 *   kernel.terminate;
 * - Messenger consumers: when a message is received and after it is handled or failed;
 * - console: when a command starts and when it terminates.
 *
 * Registered with `kernel.event_listener` tags on event names (Messenger and Console stay optional), only when
 * `resend.enabled` is true. The memo reaches it as an iterator of IGNORE_ON_UNINITIALIZED_REFERENCE references: a
 * boundary never builds it.
 *
 * @internal
 */
final readonly class LegacyResendSettingsResetListener
{
    /**
     * @param iterable<ResetInterface> $resettables Already-built legacy settings memos
     */
    public function __construct(
        private iterable $resettables,
    ) {
    }

    /**
     * Forgets the memo before a main request.
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->reset();
        }
    }

    /**
     * Forgets the memo of every already-built {@see LegacyResendSettings}.
     */
    public function reset(): void
    {
        foreach ($this->resettables as $resettable) {
            $resettable->reset();
        }
    }
}
