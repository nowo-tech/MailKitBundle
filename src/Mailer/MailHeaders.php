<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailer;

use JsonException;
use Nowo\MailKitBundle\Tracking\TrackingDecision;
use SensitiveParameter;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Message;

use function array_values;
use function base64_decode;
use function base64_encode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_substr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Internal headers of MailKit. They travel with a queued message (Messenger) and are removed before the message
 * leaves the application.
 *
 * - {@see self::TAG}: a short category shown in the sent-mail log ("password_reset", "invoice"…).
 * - {@see self::REDACT}: values masked in stored bodies (one-time links, codes) when `log.store_bodies` is on.
 * - {@see self::LOG_ID}: id of the "queued" log row, set by the mailer and resolved by the transport.
 * - {@see self::TEMPLATE}, {@see self::RECIPIENT}, {@see self::TENANT}, {@see self::NO_TRACK} (1.10): what the e-mail
 *   tracking policy is told about the message, and the per-message opt-out. Removed before delivery even with
 *   `tracking.enabled: false`.
 */
final class MailHeaders
{
    /** Log row id (internal). */
    public const LOG_ID = 'X-Mail-Kit-Log-Id';

    /** Category of the message in the log. */
    public const TAG = 'X-Mail-Kit-Tag';

    /** Base64 JSON list of values masked in stored bodies. */
    public const REDACT = 'X-Mail-Kit-Redact';

    /** Template id for the tracking policy (1.10; falls back to {@see self::TAG}). */
    public const TEMPLATE = 'X-Mail-Kit-Template';

    /** Host recipient id for the tracking policy (1.10). */
    public const RECIPIENT = 'X-Mail-Kit-Recipient';

    /** Tenant key for the tracking policy (1.10). */
    public const TENANT = 'X-Mail-Kit-Tenant';

    /** Never track this message (1.10): no pixel, links untouched, nothing stored. */
    public const NO_TRACK = 'X-Mail-Kit-No-Track';

    /** Maximum length of a tag. */
    public const TAG_MAX = 64;

    /**
     * Tags the message for the sent-mail log (replaces a previous tag).
     */
    public static function tag(Message $message, string $tag): void
    {
        $headers = $message->getHeaders();
        $headers->remove(self::TAG);
        $tag = mb_substr(trim($tag), 0, self::TAG_MAX);
        if ($tag !== '') {
            $headers->addTextHeader(self::TAG, $tag);
        }
    }

    /**
     * Template id told to the tracking policy (1.10; replaces a previous one). Without it the policy gets the tag.
     */
    public static function template(Message $message, string $templateId): void
    {
        self::replace($message, self::TEMPLATE, mb_substr(trim($templateId), 0, self::TAG_MAX));
    }

    /**
     * Host recipient id (and tenant key) told to the tracking policy (1.10). Hints only: the policy decides what is
     * stored ({@see TrackingDecision::track()}).
     */
    public static function trackingRecipient(Message $message, string $recipientId, ?string $tenant = null): void
    {
        self::replace($message, self::RECIPIENT, $recipientId);
        self::replace($message, self::TENANT, $tenant ?? '');
    }

    /**
     * Never track this message (1.10): security e-mails (magic link, password reset, codes) should call it even when
     * the policy already excludes their template.
     */
    public static function noTracking(Message $message): void
    {
        self::replace($message, self::NO_TRACK, '1');
    }

    /**
     * Values to mask in the stored bodies (added to the ones already declared).
     *
     * @throws JsonException never in practice (strings only)
     */
    public static function redact(Message $message, #[SensitiveParameter] string ...$secrets): void
    {
        $list = self::secrets($message);
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $list[] = $secret;
            }
        }
        $headers = $message->getHeaders();
        $headers->remove(self::REDACT);
        if ($list !== []) {
            $headers->addTextHeader(self::REDACT, base64_encode(json_encode($list, JSON_THROW_ON_ERROR)));
        }
    }

    /**
     * Declared values to mask.
     *
     * @return list<string>
     */
    public static function secrets(Message $message): array
    {
        $header  = $message->getHeaders()->get(self::REDACT);
        $decoded = $header instanceof HeaderInterface ? json_decode((string) base64_decode($header->getBodyAsString(), true), true) : null;
        $list    = [];
        foreach (is_array($decoded) ? array_values($decoded) : [] as $value) {
            if (is_string($value) && $value !== '') {
                $list[] = $value;
            }
        }

        return $list;
    }

    /**
     * Value of a text header, or null.
     */
    public static function value(Message $message, string $name): ?string
    {
        $value = $message->getHeaders()->get($name)?->getBodyAsString();

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * Removes every internal header (before the message leaves the application).
     */
    public static function strip(Message $message): void
    {
        foreach ([self::LOG_ID, self::TAG, self::REDACT, self::TEMPLATE, self::RECIPIENT, self::TENANT, self::NO_TRACK] as $name) {
            $message->getHeaders()->remove($name);
        }
    }

    private static function replace(Message $message, string $name, string $value): void
    {
        $headers = $message->getHeaders();
        $headers->remove($name);
        if ($value !== '') {
            $headers->addTextHeader($name, $value);
        }
    }
}
