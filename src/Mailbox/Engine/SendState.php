<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

/**
 * State of one idempotency key in `mail_kit_mailbox_send` (2.5.0, design section 9.4):
 *
 * `drafting` → `sending` → `sent`; `drafting` → `released` (nothing submitted; any call may take it over) or `failed`;
 * `sending` → `failed` (the provider refused and the draft is still a draft) or `unknown` (unprovable). Once `sending`
 * is written, only that one draft is ever submitted again for the key, and only after the provider reports it still is
 * a draft: one draft item is delivered at most once.
 *
 * @internal
 */
enum SendState: string
{
    /** Claimed: the draft is being created and filled (nothing submitted). */
    case Drafting = 'drafting';

    /** Abandoned before the submit (transient or grant failure): taken over at once by the next call. */
    case Released = 'released';

    /** The submit is about to run or ran without a definite answer: reconciled by the draft state only. */
    case Sending = 'sending';

    /** Sent; the ids are stored. */
    case Sent = 'sent';

    /** Refused for good by the provider; nothing was sent. */
    case Failed = 'failed';

    /** Unprovable; never submitted again. */
    case Unknown = 'unknown';
}
