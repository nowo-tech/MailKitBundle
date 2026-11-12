<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

/**
 * Allow-list port of the links that MAY be rewritten (1.10, `nowo_mail_kit.tracking.link_filter`; default
 * {@see ConfiguredTrackableLinkFilter} over `tracking.links.allowed_hosts` / `allowed_path_prefixes`, which allows
 * nothing until hosts are listed).
 *
 * Asked only for absolute `http(s)` links that {@see CredentialLinkDetector} did not refuse: a credential-bearing link
 * (magic link, password reset, one-time code, token in the query…) is never rewritten, whatever this port answers. An
 * exception keeps the link. Must keep no per-send state.
 */
interface TrackableLinkFilterInterface
{
    /**
     * True when the link may go through the link rewriter.
     *
     * @param string $url absolute http(s) URL
     * @param string|null $templateId template id of the e-mail
     */
    public function mayRewrite(string $url, ?string $templateId): bool;
}
