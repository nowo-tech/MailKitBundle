<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph;

/**
 * Host port (1.8): the Microsoft Graph mailbox and app-only credentials of a profile. The default implementation
 * ({@see ConfiguredGraphMailboxProvider}) reads `nowo_mail_kit.graph.profiles`; a host that keeps the tenant, client
 * id and encrypted secret in its own settings (per organization, per instance…) implements this port and sets
 * `nowo_mail_kit.graph.mailbox_provider`.
 *
 * Called on every send (FrankenPHP worker mode: resolve per call, never cache a tenant's credentials in a property).
 */
interface GraphMailboxProviderInterface
{
    /**
     * The mailbox of a profile.
     *
     * @throws GraphException with reason {@see GraphException::REASON_CONFIGURATION} when the profile is unknown or not configured
     */
    public function mailbox(string $profile): GraphMailbox;

    /**
     * Name of the profile used when the caller names none.
     */
    public function defaultProfile(): string;
}
