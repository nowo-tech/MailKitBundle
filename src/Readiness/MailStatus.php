<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Readiness;

use Nowo\MailKitBundle\Dsn\DsnSource;

/**
 * Snapshot of the outbound mail configuration, safe to render: where the DSN comes from, its redacted label, whether
 * mail can be delivered and the validation error key when it cannot.
 */
final readonly class MailStatus
{
    /**
     * @param string $label redacted `scheme://host[:port]`, or "default" for the framework transport
     * @param string|null $error validation error translation key (`NowoMailKitBundle` domain), or null
     */
    public function __construct(
        public DsnSource $source,
        public string $label,
        public bool $ready,
        public ?string $error = null,
    ) {
    }
}
