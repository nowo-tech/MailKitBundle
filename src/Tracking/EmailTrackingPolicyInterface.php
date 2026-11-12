<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

/**
 * Host port (1.10, `nowo_mail_kit.tracking.policy`, required with `tracking.enabled`): decides per template and
 * recipient whether an e-mail is tracked — e.g. never the security templates (magic link, password reset, one-time
 * codes, sign-in alerts, e-mail change, invitation accept) and never a recipient who opted out.
 *
 * Called at delivery time (in the Messenger worker for queued mail), once per e-mail, before anything is stored. An
 * exception counts as {@see TrackingDecision::none()} (logged). Must keep no per-recipient state (FrankenPHP worker
 * mode): resolve the recipient and its preferences on every call.
 */
interface EmailTrackingPolicyInterface
{
    /**
     * Track this e-mail, or not.
     */
    public function decide(TrackingCandidate $candidate): TrackingDecision;
}
