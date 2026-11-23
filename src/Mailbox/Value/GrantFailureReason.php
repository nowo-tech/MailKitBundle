<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

/**
 * Why a mailbox was paused by a grant failure (2.5.0, design section 13).
 */
enum GrantFailureReason: string
{
    /** The grant is gone: revoked, refresh token expired, or a fresh token was refused (401 twice). */
    case Revoked = 'revoked';

    /** The provider refused the call for missing consent (Graph 403). */
    case ConsentMissing = 'consent_missing';

    /** The account behind the token is not the mailbox's address, or not the account first seen. */
    case AccountMismatch = 'account_mismatch';

    /** No mailbox behind the account (Graph MailboxNotEnabledForRESTAPI / ErrorInvalidUser). */
    case MailboxNotFound = 'mailbox_not_found';

    /** The token lacks a scope MailKit needs. */
    case ScopeMissing = 'scope_missing';
}
