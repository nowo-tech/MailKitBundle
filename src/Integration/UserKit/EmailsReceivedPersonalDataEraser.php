<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Integration\UserKit;

use Nowo\MailKitBundle\Log\SentMailErasureInterface;
use Nowo\MailKitBundle\Log\SentMailPersonalDataSubject;
use Nowo\MailKitBundle\Log\SentMailPersonalDataSubjectResolverInterface;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;

use function array_values;
use function is_string;

/**
 * UserKit personal-data eraser `emails_received` (1.7; registered with UserKit installed, the log entities mapped and
 * `personal_data.enabled`): {@see SentMailErasureInterface::forgetAddress()} once per distinct address of the user —
 * the subjects of {@see SentMailPersonalDataSubjectResolverInterface} (by default the original identifier when it is
 * an e-mail and `getEmail()`) plus `context['previous_email']` when it is a non-empty string. Runs before the host
 * identity eraser (priority {@see self::PRIORITY}), while the account still has its address. Inside UserKit's
 * transaction the erasure joins it (DBAL statements, no flush) and no MailKit event is dispatched.
 */
final readonly class EmailsReceivedPersonalDataEraser implements PersonalDataEraserInterface
{
    /** Eraser key (stable). */
    public const KEY = 'emails_received';

    /** Tag priority: before the host identity eraser (REQ-DATA-002). */
    public const PRIORITY = 110;

    /** Request context key of the address the user had before an e-mail change. */
    public const PREVIOUS_EMAIL = 'previous_email';

    /**
     * @param SentMailErasureInterface $erasure the log erasure port
     * @param SentMailPersonalDataSubjectResolverInterface $subjects addresses of the user
     */
    public function __construct(
        private SentMailErasureInterface $erasure,
        private SentMailPersonalDataSubjectResolverInterface $subjects,
    ) {
    }

    /**
     * `emails_received`.
     */
    public function getKey(): string
    {
        return self::KEY;
    }

    /**
     * Every user: one without an address simply has nothing to erase.
     */
    public function supports(object $user): bool
    {
        return true;
    }

    /**
     * Erases the log rows of every address of the user (no flush).
     */
    public function erase(ErasureRequest $request): void
    {
        $subjects = [];
        foreach ($this->subjects->subjectsFor($request->user, $request->identifier()) as $subject) {
            $subjects[$subject->email] = $subject;
        }
        $previous = $request->context[self::PREVIOUS_EMAIL] ?? null;
        $previous = SentMailPersonalDataSubject::tryFromEmail(is_string($previous) ? $previous : null);
        if ($previous instanceof SentMailPersonalDataSubject) {
            $subjects[$previous->email] = $previous;
        }

        foreach (array_values($subjects) as $subject) {
            $this->erasure->forgetAddress($subject->email);
        }
    }
}
