<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use LogicException;
use Nowo\MailKitBundle\Tracking\EmailTrackingPolicyInterface;
use Nowo\MailKitBundle\Tracking\TrackingCandidate;
use Nowo\MailKitBundle\Tracking\TrackingDecision;

use function in_array;
use function strstr;

/**
 * Host policy double like Beacon's: security templates and opted-out recipients are never tracked; the recipient id is
 * the declared hint or "user-<local part>"; template "boom" throws; "pixel_only" / "links_only" track half.
 */
final class HostTrackingPolicy implements EmailTrackingPolicyInterface
{
    /** Security templates of the host (never tracked). */
    public const SECURITY = ['password_reset', 'magic_link', 'login_code', 'one_time_code', 'email_change', 'invitation_accept', 'new_device_alert', 'sign_in_alert', 'verify_email', 'two_factor_code'];

    /**
     * @param list<string> $optedOut addresses that turned tracking off
     */
    public function __construct(
        public array $optedOut = ['optout@example.com'],
    ) {
    }

    public function decide(TrackingCandidate $candidate): TrackingDecision
    {
        if ($candidate->templateId === 'boom') {
            throw new LogicException('policy failure');
        }
        if (in_array($candidate->templateId, self::SECURITY, true) || in_array($candidate->recipient, $this->optedOut, true)) {
            return TrackingDecision::none();
        }
        $id = $candidate->recipientId ?? 'user-' . strstr($candidate->recipient, '@', true);

        return TrackingDecision::track($id, $candidate->tenant, $candidate->templateId !== 'links_only', $candidate->templateId !== 'pixel_only');
    }
}
