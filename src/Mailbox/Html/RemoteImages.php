<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Html;

/**
 * What the mail HTML sanitiser does with remote images (2.5.0, design section 11, Q-MB-8).
 */
enum RemoteImages: string
{
    /**
     * `<img src="https://…">` loses its `src`; the URL (absolute https, no user info, at most 2 048 characters,
     * re-validated) moves to `data-mail-kit-remote-src` for the host's *Show images* (through its own image proxy).
     * Other remote media (`srcset`, `poster`, `background`, audio / video) are removed.
     */
    case Blocked = 'blocked';

    /** Remote images are removed with their URL (the 1.9 default). */
    case Dropped = 'dropped';

    /** `http(s)` image sources are kept: tracking pixels reach the sender. Not for a strict CSP. */
    case Allowed = 'allowed';

    /**
     * 2.7.0 (stored e-mail templates): absolute `https` image sources and the message's own `cid:` images are kept;
     * `http`, `data:` and relative sources are removed, and so are every other URL-bearing media attribute (`srcset`,
     * `poster`, `background`, `style`, …), the `source` / `track` / `link` / `style` elements and audio / video. Not
     * offered by `mailboxes.html.remote_images`.
     */
    case HttpsOnly = 'https_only';
}
