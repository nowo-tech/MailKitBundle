<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use InvalidArgumentException;

use function mb_strlen;
use function preg_match;
use function str_contains;

/**
 * Whose sent-mail log rows are exported or erased (1.7, GDPR art. 15 / 17 / 20): one e-mail address, in the form the
 * log stores it. The log normalises every address on write ({@see SentMailRecorder::normalize()}: trimmed, NFC,
 * lower-case, Unicode domain), so the subject applies the same normalisation and nothing else: `Alice@Example.com`
 * and `alice@example.com` are the same address in the log, `jose@example.com` and `josé@example.com` are not.
 *
 * Plain PHP value object (no Doctrine, Symfony or HTTP types).
 */
final readonly class SentMailPersonalDataSubject
{
    /** Longest accepted address (the `sender` column). */
    public const MAX_LENGTH = 255;

    /**
     * @param string $email normalised address ({@see SentMailRecorder::normalize()})
     */
    private function __construct(
        public string $email,
    ) {
    }

    /**
     * The subject of one address.
     *
     * @throws InvalidArgumentException for an empty value, a value without `@`, control characters (NUL included),
     *                                  invalid UTF-8 or more than {@see self::MAX_LENGTH} characters once normalised
     */
    public static function fromEmail(string $email): self
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $email) === 1) {
            throw new InvalidArgumentException('A sent-mail personal-data subject must not contain control characters.');
        }
        $normalized = SentMailRecorder::normalize($email);
        if ($normalized === '' || !str_contains($normalized, '@')) {
            throw new InvalidArgumentException('A sent-mail personal-data subject must be an e-mail address.');
        }
        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('A sent-mail personal-data subject must not be longer than 255 characters.');
        }

        return new self($normalized);
    }

    /**
     * {@see self::fromEmail()}, or null when the value is not an acceptable address (host user data, e.g. a username).
     */
    public static function tryFromEmail(?string $email): ?self
    {
        if ($email === null) {
            return null;
        }
        try {
            return self::fromEmail($email);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Unicode and punycode forms matched against the log ({@see SentMailRecorder::forms()}).
     *
     * @return list<string>
     */
    public function forms(): array
    {
        return SentMailRecorder::forms($this->email);
    }
}
