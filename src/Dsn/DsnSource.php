<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Dsn;

/**
 * Where the effective mailer DSN comes from.
 */
enum DsnSource: string
{
    /** `mailer.dsn` secret of the Instance Settings mailer section. */
    case Settings = 'settings';

    /** `resend.api_key` secret of the Resend section (resend+api://…@default). */
    case Resend = 'resend';

    /** A host {@see MailerDsnProviderInterface} implementation. */
    case Custom = 'custom';

    /** Nothing stored: the framework transport (framework.mailer.dsn, usually MAILER_DSN). */
    case Fallback = 'fallback';
}
