<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use function array_values;
use function is_string;
use function method_exists;

/**
 * Default {@see SentMailPersonalDataSubjectResolverInterface} (1.7): the original user identifier when it is an e-mail
 * address, plus the user's `getEmail()` when the user has one. Values that are not addresses (a username, an id) are
 * skipped. Stateless.
 */
final readonly class DefaultSentMailPersonalDataSubjectResolver implements SentMailPersonalDataSubjectResolverInterface
{
    /**
     * The identifier and `getEmail()` that are e-mail addresses, deduplicated after normalisation.
     *
     * @return list<SentMailPersonalDataSubject>
     */
    public function subjectsFor(object $user, ?string $originalIdentifier): array
    {
        $candidates = [$originalIdentifier];
        if (method_exists($user, 'getEmail')) {
            $email        = $user->getEmail();
            $candidates[] = is_string($email) ? $email : null;
        }

        $subjects = [];
        foreach ($candidates as $candidate) {
            $subject = SentMailPersonalDataSubject::tryFromEmail($candidate);
            if ($subject instanceof SentMailPersonalDataSubject) {
                $subjects[$subject->email] = $subject;
            }
        }

        return array_values($subjects);
    }
}
