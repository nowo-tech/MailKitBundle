<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Value;

use Nowo\MailKitBundle\Template\Exception\InvalidTemplate;

use function mb_strlen;
use function preg_match;
use function trim;

/**
 * Input of a create, an update or a preview (2.7.0). The constructor checks the grammar (key, locale, name, subject
 * line); sizes, placeholder counts and the HTML sanitising are applied by the library with the configured limits.
 *
 * - key: `[a-z0-9][a-z0-9._-]{0,63}`, unique per tenant, kind, scope, owner (personal) and locale;
 * - locale: `xx`, `xxx` or `xx_XX`;
 * - name: 1 to {@see MAX_NAME} characters after trimming, no control character;
 * - subject: a template needs 1 to {@see MAX_SUBJECT} characters without control characters (no line break, so no
 *   header injection); a snippet has none (empty string);
 * - html / text: at least one of them is not blank.
 */
final readonly class TemplateDraft
{
    /** Accepted key. */
    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}\z/';

    /** Accepted locale. */
    public const LOCALE_PATTERN = '/^[a-z]{2,3}(?:_[A-Z]{2})?\z/';

    /** Longest name, in characters. */
    public const MAX_NAME = 190;

    /** Longest subject, in characters. */
    public const MAX_SUBJECT = 255;

    /**
     * Control, bidi override / isolate, line / paragraph separator, zero-width (U+200B, U+2060, U+FEFF) and direction
     * mark (U+200E, U+200F, U+061C) characters (ZCZ-I3).
     */
    public const UNSAFE_LINE = '/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}\p{Zl}\p{Zp}\x{200B}\x{2060}\x{FEFF}\x{200E}\x{200F}\x{061C}]/u';

    public string $name;

    /**
     * @throws InvalidTemplate when a field breaks the grammar above
     */
    public function __construct(
        public TemplateKind $kind,
        public TemplateScope $scope,
        public string $key,
        string $name,
        public string $locale,
        public string $subject,
        public string $html,
        public string $text,
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidTemplate('key', 'The key must be 1 to 64 characters among a-z, 0-9, ".", "_" and "-", starting with a letter or a digit.');
        }
        if (preg_match(self::LOCALE_PATTERN, $locale) !== 1) {
            throw new InvalidTemplate('locale', 'The locale must read like "es" or "es_ES".');
        }
        $name = trim($name);
        if (!$this->isLine($name, self::MAX_NAME)) {
            throw new InvalidTemplate('name', 'The name must be 1 to 190 characters without control characters.');
        }
        $this->name = $name;
        if ($kind === TemplateKind::Snippet && $subject !== '') {
            throw new InvalidTemplate('subject', 'A snippet has no subject.');
        }
        if ($kind === TemplateKind::Template && !$this->isLine($subject, self::MAX_SUBJECT)) {
            throw new InvalidTemplate('subject', 'The subject must be 1 to 255 characters without control characters or line breaks.');
        }
        if (preg_match('//u', $html) !== 1 || preg_match('//u', $text) !== 1) {
            throw new InvalidTemplate('body', 'The bodies must be valid UTF-8.');
        }
        if (trim($html) === '' && trim($text) === '') {
            throw new InvalidTemplate('body', 'A template or snippet needs an HTML or a text body.');
        }
    }

    /**
     * A non-blank single line of valid UTF-8, at most `$max` characters, without unsafe characters.
     */
    private function isLine(string $value, int $max): bool
    {
        return trim($value) !== ''
            && preg_match('//u', $value) === 1
            && mb_strlen($value, 'UTF-8') <= $max
            && preg_match(self::UNSAFE_LINE, $value) !== 1;
    }
}
