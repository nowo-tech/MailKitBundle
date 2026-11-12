<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound;

use DateTimeImmutable;
use Exception;
use Nowo\MailKitBundle\Graph\Send\PlusAddress;

use function array_keys;
use function array_slice;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function mb_strcut;
use function mb_substr;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function str_contains;
use function strlen;
use function strtolower;
use function trim;

/**
 * Turns a Graph message resource (JSON, as fetched by {@see GraphInboundProcessor}) into a {@see GraphInboundMessage}
 * (1.9): addresses with plus-address tags, sanitised HTML and derived text, threading ids from
 * `internetMessageHeaders`, SPF / DKIM / DMARC from the first `Authentication-Results`, and a forwarded flag. Pure
 * function of its input (no state, no I/O).
 */
final class GraphMessageNormalizer
{
    /** Longest subject kept (RFC 5322 line limit). */
    public const MAX_SUBJECT = 998;

    /** Most References kept (the newest). */
    public const MAX_REFERENCES = 100;

    /** Headers that may name the envelope recipient (plus-address tags of a Bcc / forward). */
    public const ENVELOPE_HEADERS = ['delivered-to', 'x-original-to', 'envelope-to', 'x-ms-exchange-organization-originalenveloperecipients', 'x-forwarded-to'];

    /** Subject prefixes of forwarded mail (en, es, de, fr, it, nl, pt, sv, …). */
    private const FORWARD_SUBJECT = '/^\s*(?:\[[^\]]{0,40}\]\s*)?(?:fwd?|fw|rv|reenv|wg|tr|i|inoltro|enc|doorst|vs|vb)\s*:/iu';

    /** Body markers of forwarded mail (Outlook, Gmail, Apple Mail; en / es). */
    private const FORWARD_BODY = '/(?:-{3,}\s*(?:Forwarded message|Original Message|Mensaje original|Mensaje reenviado)\s*-{3,}|Begin forwarded message:|Inicio del mensaje reenviado:)/iu';

    /**
     * @param InboundHtmlSanitizer $sanitizer HTML sanitizer of the inbound bodies
     */
    public function __construct(
        private readonly InboundHtmlSanitizer $sanitizer,
    ) {
    }

    /**
     * The normalised message.
     *
     * @param array<mixed> $data Graph message resource
     * @param list<InboundAttachment> $attachments
     * @param bool $attachedMessage an attached e-mail (item attachment) was found
     * @param bool $oversized the message was over the size limit: no body or headers were read
     */
    public function normalize(string $profile, string $mailbox, array $data, array $attachments, bool $attachedMessage, bool $oversized = false): GraphInboundMessage
    {
        $headers = $oversized ? [] : $this->headers($data['internetMessageHeaders'] ?? null);
        $to      = $this->recipients($data['toRecipients'] ?? null, $mailbox);
        $cc      = $this->recipients($data['ccRecipients'] ?? null, $mailbox);
        $subject = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $this->string($data['subject'] ?? null))), 0, self::MAX_SUBJECT);

        $body    = !$oversized && is_array($data['body'] ?? null) ? $data['body'] : [];
        $content = $this->string($body['content'] ?? null);
        $html    = null;
        if (strtolower($this->string($body['contentType'] ?? null)) === 'html') {
            $html = $this->sanitizer->sanitize($content);
            $text = $this->sanitizer->toText($html);
        } else {
            $text = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', mb_strcut($content, 0, 1_048_576)));
        }

        $tags = [];
        foreach ([...$to, ...$cc] as $address) {
            if ($address->tag !== null) {
                $tags[$address->tag] = true;
            }
        }
        foreach (self::ENVELOPE_HEADERS as $name) {
            foreach ($headers[$name] ?? [] as $value) {
                preg_match_all('/[^\s<>,;"]+@[^\s<>,;"]+/', $value, $found);
                foreach ($found[0] as $candidate) {
                    $tag = PlusAddress::tag($candidate, $mailbox);
                    if ($tag !== null) {
                        $tags[$tag] = true;
                    }
                }
            }
        }

        $references = $this->ids($headers['references'][0] ?? '');
        if (count($references) > self::MAX_REFERENCES) {
            $references = array_values(array_slice($references, -self::MAX_REFERENCES));
        }
        $forwarded = $attachedMessage
            || preg_match(self::FORWARD_SUBJECT, $subject) === 1
            || isset($headers['resent-from']) || isset($headers['x-forwarded-for']) || isset($headers['x-forwarded-to'])
            || preg_match(self::FORWARD_BODY, mb_strcut($text, 0, 65_536)) === 1;

        return new GraphInboundMessage(
            profile: $profile,
            graphId: $this->string($data['id'] ?? null),
            internetMessageId: $this->ids($this->string($data['internetMessageId'] ?? null))[0] ?? trim($this->string($data['internetMessageId'] ?? null), '<> '),
            inReplyTo: $this->ids($headers['in-reply-to'][0] ?? '')[0] ?? null,
            references: $references,
            conversationId: $this->token($data['conversationId'] ?? null),
            from: $this->address($data['from'] ?? null, $mailbox),
            sender: $this->address($data['sender'] ?? null, $mailbox),
            replyTo: $this->recipients($data['replyTo'] ?? null, $mailbox),
            to: $to,
            cc: $cc,
            subject: $subject,
            text: $text,
            html: $html,
            receivedAt: $this->date($data['receivedDateTime'] ?? null),
            attachments: $attachments,
            authentication: AuthenticationResults::fromExchange($headers['authentication-results'][0] ?? null, $headers['x-ms-exchange-organization-authas'][0] ?? null),
            forwarded: $forwarded,
            plusTags: array_keys($tags),
            oversized: $oversized,
        );
    }

    /**
     * Header values by lower-cased name, in Graph's order (topmost first).
     *
     * @return array<string, list<string>>
     */
    private function headers(mixed $list): array
    {
        $headers = [];
        foreach (is_array($list) ? $list : [] as $header) {
            if (is_array($header) && is_string($header['name'] ?? null) && is_string($header['value'] ?? null) && strlen($header['value']) <= 65_536) {
                $headers[strtolower(trim($header['name']))][] = $header['value'];
            }
        }

        return $headers;
    }

    /**
     * @return list<InboundAddress>
     */
    private function recipients(mixed $list, string $mailbox): array
    {
        $out = [];
        foreach (is_array($list) ? array_slice($list, 0, 500) : [] as $recipient) {
            $address = $this->address($recipient, $mailbox);
            if ($address instanceof InboundAddress) {
                $out[] = $address;
            }
        }

        return $out;
    }

    private function address(mixed $recipient, string $mailbox): ?InboundAddress
    {
        $email = is_array($recipient) && is_array($recipient['emailAddress'] ?? null) ? $recipient['emailAddress'] : null;
        if ($email === null || !is_string($email['address'] ?? null) || !str_contains($email['address'], '@')) {
            return null;
        }

        return new InboundAddress($email['address'], is_string($email['name'] ?? null) ? $email['name'] : null, $mailbox);
    }

    /**
     * Message ids (`<…>`) of a header value, without brackets (at most 250 characters each).
     *
     * @return list<string>
     */
    private function ids(string $value): array
    {
        preg_match_all('/<([^<>\s]{1,250}@[^<>\s]{1,250})>/', $value, $matches);

        return array_values($matches[1]);
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function token(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9+\/=_-]{1,512}\z/', $value) === 1 ? $value : null;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
