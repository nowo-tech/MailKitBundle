<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\EventListener;

use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\MailKitBundle\Mailer\RuntimeTransport;
use Nowo\MailKitBundle\Readiness\OutboundMailReadiness;
use Nowo\MailKitBundle\Settings\MailerSettingsSection;
use Nowo\MailKitBundle\Settings\ResendSettingsSection;
use Psr\Log\LoggerInterface;

use function implode;
use function in_array;

/**
 * Reacts to saves of the `mailer` / `resend` sections: drops the cached transport (the next send rebuilds it from
 * the new DSN) and writes an audit log line with the field names, the origin and the redacted new transport
 * (`scheme://host`) — never a value.
 */
final readonly class SettingsChangedListener
{
    /**
     * @param RuntimeTransport $transport the runtime transport to reset
     */
    public function __construct(
        private RuntimeTransport $transport,
        private OutboundMailReadiness $readiness,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Resets the transport and audits the change.
     */
    public function __invoke(SettingsChangedEvent $event): void
    {
        if (!in_array($event->section, [MailerSettingsSection::NAME, ResendSettingsSection::NAME], true)) {
            return;
        }

        $this->transport->reset();
        $status = $this->readiness->status();
        $this->logger?->notice('Mail settings changed.', [
            'bundle'    => 'NowoMailKitBundle',
            'section'   => $event->section,
            'fields'    => implode(',', $event->changedFields),
            'secrets'   => implode(',', $event->secretFields),
            'origin'    => $event->origin,
            'source'    => $status->source->value,
            'transport' => $status->label,
            'ready'     => $status->ready,
        ]);
    }
}
