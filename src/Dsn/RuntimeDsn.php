<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Dsn;

use SensitiveParameter;

/**
 * A mailer DSN resolved at runtime and where it comes from. The DSN holds credentials: never log or render it
 * (use {@see MailerDsnAudit::label()}).
 */
final readonly class RuntimeDsn
{
    /**
     * @param string $dsn full DSN, credentials included
     */
    public function __construct(
        #[SensitiveParameter]
        public string $dsn,
        public DsnSource $source = DsnSource::Custom,
    ) {
    }

    /**
     * Redacted `scheme://host[:port]`.
     */
    public function label(): string
    {
        return MailerDsnAudit::label($this->dsn);
    }
}
