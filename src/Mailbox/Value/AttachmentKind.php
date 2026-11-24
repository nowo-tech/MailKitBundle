<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use Nowo\MailKitBundle\Mailbox\Service\MailboxAttachmentReaderInterface;

/**
 * Kind of an attachment of a synced message (2.5.0, Q-MB-22).
 */
enum AttachmentKind: string
{
    /** A file: downloadable through {@see MailboxAttachmentReaderInterface}. */
    case File = 'file';

    /** An attached e-mail or calendar item: listed, not downloadable in 2.5.0. */
    case Item = 'item';

    /** A link to a cloud file (OneDrive): listed, not downloadable. */
    case Reference = 'reference';
}
