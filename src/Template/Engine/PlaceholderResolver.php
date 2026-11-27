<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Engine;

use Nowo\MailKitBundle\Template\Exception\InvalidTemplate;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderContext;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderDefinition;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderProviderInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

use function array_keys;
use function array_unique;
use function array_values;
use function htmlspecialchars;
use function is_string;
use function mb_substr;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function sort;
use function str_replace;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const PREG_SET_ORDER;

/**
 * The closed placeholder registry and its substitution (2.7.0). Grammar: `{{ namespace.name }}` with optional spaces
 * inside the braces, namespace `[a-z][a-z0-9_]{0,31}`, name `[a-z0-9_]{1,40}`; anything else is text. Each provider
 * is asked once per render with the distinct names of its namespace; a placeholder without a value stays as the
 * literal text of the template. The substitution is one pass per part, so a value that looks like a placeholder is
 * never expanded again, and nothing is evaluated.
 *
 * Values: invalid UTF-8 counts as no value; control and bidi override / isolate characters are removed (line breaks
 * and tabs kept in the text body, turned into spaces in the subject); HTML-escaped in the HTML body.
 *
 * Stateless: the locator is immutable (worker mode).
 *
 * @internal
 */
final readonly class PlaceholderResolver
{
    /** A placeholder occurrence. */
    public const PATTERN = '/\{\{\s{0,8}([a-z][a-z0-9_]{0,31})\.([a-z0-9_]{1,40})\s{0,8}\}\}/';

    /** Longest rendered subject, in characters (RFC 5322 line length). */
    public const MAX_SUBJECT = 998;

    private const UNSAFE_TEXT    = '/[\x00-\x08\x0B-\x1F\x7F\x{80}-\x{9F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';
    private const UNSAFE_SUBJECT = '/[\p{Cc}\p{Zl}\p{Zp}]/u';
    private const BIDI           = '/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /**
     * @param ServiceProviderInterface<PlaceholderProviderInterface> $providers namespace => provider
     * @param int $max most occurrences in one template
     */
    public function __construct(
        private ServiceProviderInterface $providers,
        private int $max,
    ) {
    }

    /**
     * Occurrences in the given parts.
     */
    public function count(string ...$parts): int
    {
        $count = 0;
        foreach ($parts as $part) {
            $count += (int) preg_match_all(self::PATTERN, $part);
        }

        return $count;
    }

    /**
     * Throws when the parts hold more than the configured occurrences.
     *
     * @throws InvalidTemplate
     */
    public function assertCount(string ...$parts): void
    {
        if ($this->count(...$parts) > $this->max) {
            throw new InvalidTemplate('placeholders', 'The template has too many placeholders.');
        }
    }

    /**
     * The registered namespaces' definitions, namespaces sorted.
     *
     * @return array<string, list<PlaceholderDefinition>>
     */
    public function definitions(): array
    {
        $namespaces = array_keys($this->providers->getProvidedServices());
        sort($namespaces);
        $out = [];
        foreach ($namespaces as $namespace) {
            $out[$namespace] = $this->providers->get($namespace)->definitions();
        }

        return $out;
    }

    /**
     * Substitutes the three parts.
     *
     * @throws InvalidTemplate for too many occurrences
     *
     * @return array{subject: string, html: string, text: string, unresolved: list<string>}
     */
    public function substitute(string $subject, string $html, string $text, PlaceholderContext $context): array
    {
        $this->assertCount($subject, $html, $text);
        $wanted = [];
        foreach ([$subject, $html, $text] as $part) {
            preg_match_all(self::PATTERN, $part, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $wanted[$match[1]][$match[2]] = true;
            }
        }
        $values = [];
        foreach ($wanted as $namespace => $names) {
            if (!$this->providers->has($namespace)) {
                continue;
            }
            $asked = array_keys($names);
            foreach ($this->providers->get($namespace)->resolve($asked, $context) as $name => $value) {
                if (isset($names[$name]) && is_string($value) && preg_match('//u', $value) === 1) {
                    $values[$namespace . '.' . $name] = $value;
                }
            }
        }
        $unresolved = [];
        foreach ($wanted as $namespace => $names) {
            foreach (array_keys($names) as $name) {
                if (!isset($values[$namespace . '.' . $name])) {
                    $unresolved[] = $namespace . '.' . $name;
                }
            }
        }

        return [
            'subject'    => mb_substr(trim($this->replace($subject, $values, static fn (string $v): string => (string) preg_replace(self::UNSAFE_SUBJECT, ' ', (string) preg_replace(self::BIDI, '', $v)))), 0, self::MAX_SUBJECT, 'UTF-8'),
            'html'       => $this->replace($html, $values, static fn (string $v): string => htmlspecialchars(self::cleanText($v), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')),
            'text'       => $this->replace($text, $values, self::cleanText(...)),
            'unresolved' => array_values(array_unique($unresolved)),
        ];
    }

    /**
     * @param array<string, string> $values
     * @param callable(string): string $encode
     */
    private function replace(string $part, array $values, callable $encode): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            static fn (array $m): string => isset($values[$m[1] . '.' . $m[2]]) ? $encode($values[$m[1] . '.' . $m[2]]) : $m[0],
            $part,
        );
    }

    /**
     * Line breaks normalised to `\n`; other control and bidi characters removed.
     */
    private static function cleanText(string $value): string
    {
        return (string) preg_replace(self::UNSAFE_TEXT, '', str_replace(["\r\n", "\r"], "\n", $value));
    }
}
