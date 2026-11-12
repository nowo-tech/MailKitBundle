<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

use Closure;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\MailKitBundle\Tracking\Store\TrackingStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Throwable;

use function count;
use function fnmatch;
use function is_resource;
use function is_string;
use function mb_strlen;
use function parse_url;
use function preg_match;
use function rewind;
use function str_contains;
use function str_starts_with;
use function stream_get_contents;
use function stream_get_meta_data;
use function strlen;
use function strtolower;

use const FNM_CASEFOLD;
use const PHP_URL_HOST;

/**
 * Prepares the tracked copy of an outgoing e-mail (1.10), called by the runtime transport right before delivery (in
 * the Messenger worker for queued mail). The caller's message is never changed: the tracked copy is a clone, and the
 * transport strips the internal headers from the copy it delivers only (audit NU-H1). A Messenger retry therefore
 * re-sends the original with its headers intact (`X-Mail-Kit-No-Track`, template, tag, redaction values), is decided
 * again from scratch and, when tracked, gets a new send id (the failed attempt's send was discarded).
 *
 * In order — any "no" sends the original untouched and stores nothing:
 *
 * 1. an {@see Email} with an HTML body (templated e-mails are rendered first) of at most `tracking.max_html_bytes`,
 *    exactly one envelope recipient, no `X-Mail-Kit-No-Track` header, no pixel of this application already;
 * 2. its template id (`X-Mail-Kit-Template`, else the tag) does not match {@see self::SECURITY_TEMPLATES} (always) or
 *    `tracking.extra_never_track_templates`, and it
 *    declares no redaction values while `tracking.skip_redacted` is on (one-time links and codes);
 * 3. the host {@see EmailTrackingPolicyInterface} answers {@see TrackingDecision::track()} (an exception = no);
 * 4. the pixel signer is available (a bad secret = no, logged as critical) and the send row is stored (a store
 *    failure = no).
 *
 * Then each absolute http(s) link that is not credential-bearing ({@see CredentialLinkDetector}), not this
 * application's pixel and allowed by the {@see TrackableLinkFilterInterface} goes through the
 * {@see EmailLinkRewriterInterface}; the text part gets the same tracking URLs; the pixel is added. Stateless (FrankenPHP
 * worker mode): everything lives in the call.
 */
final readonly class EmailTracker
{
    /** Longest tracking URL accepted from the rewriter. */
    public const MAX_URL = 2048;

    /**
     * Template id patterns (`fnmatch`, case-insensitive) that are never tracked, whatever the configuration or the
     * policy says (audit NU-M2): security e-mails. `tracking.extra_never_track_templates` adds to them.
     */
    public const SECURITY_TEMPLATES = ['*password*', '*reset*', '*magic*', '*login*', '*sign_in*', '*signin*', '*one_time*', '*otp*', '*code*', '*verif*', '*confirm*', '*security*', '*new_device*', '*email_change*', '*invitation*', '*invite*', '*2fa*', '*mfa*', '*token*'];

    /**
     * @param string $pixelBase absolute URL prefix of the pixel (`tracking.public_base_url` + `tracking.path_prefix`)
     * @param Closure(): TrackingTokenSigner|TrackingTokenSigner $signer the signer, or a factory of it (built lazily so
     *                                                                   a bad secret leaves mail untracked, not undelivered)
     * @param list<string> $neverTrackTemplates extra `fnmatch` patterns (case-insensitive) of template ids never
     *                                          tracked, on top of {@see self::SECURITY_TEMPLATES}
     * @param bool $skipRedacted never track an e-mail that declares redaction values
     * @param list<string> $essentialPatterns case-insensitive substrings of the URL or label of essential links
     * @param int $maxHtmlBytes larger HTML bodies are sent untracked
     * @param BodyRendererInterface|null $renderer renders a templated e-mail before it is tracked (`twig.mime_body_renderer`)
     */
    public function __construct(
        private EmailTrackingPolicyInterface $policy,
        private EmailLinkRewriterInterface $rewriter,
        private TrackableLinkFilterInterface $filter,
        private CredentialLinkDetector $credentials,
        private TrackingTokenSigner|Closure $signer,
        private TrackingStore $store,
        private HtmlLinkTracker $html,
        private string $pixelBase,
        private array $neverTrackTemplates = [],
        private bool $skipRedacted = true,
        private array $essentialPatterns = [],
        private int $maxHtmlBytes = 2_097_152,
        private ?BodyRendererInterface $renderer = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The tracked copy to deliver, or null to deliver the original. Never throws.
     *
     * @param string|null $logId sent-mail log row id of a queued message
     */
    public function track(Message $message, ?Envelope $envelope = null, ?string $logId = null): ?TrackedEmail
    {
        try {
            return $this->prepare($message, $envelope, $logId);
        } catch (Throwable $exception) {
            $this->logger?->warning('Mail tracking skipped: the e-mail is sent untracked.', ['bundle' => 'NowoMailKitBundle', 'error' => $exception::class]);

            return null;
        }
    }

    /**
     * Forgets the send of a failed delivery.
     */
    public function discard(TrackedEmail $tracked): void
    {
        $this->store->discard($tracked->sendId);
    }

    /**
     * Links the send to its sent-mail log row.
     */
    public function attachLog(TrackedEmail $tracked, string $logId): void
    {
        $this->store->attachLog($tracked->sendId, $logId);
    }

    private function prepare(Message $message, ?Envelope $envelope, ?string $logId): ?TrackedEmail
    {
        if (!$message instanceof Email || $message->getHeaders()->has(MailHeaders::NO_TRACK)) {
            return null;
        }
        $template = MailHeaders::value($message, MailHeaders::TEMPLATE) ?? MailHeaders::value($message, MailHeaders::TAG);
        $secrets  = MailHeaders::secrets($message);
        if ($this->neverTracked($template) || ($this->skipRedacted && $secrets !== [])) {
            return null;
        }
        $recipients = ($envelope ?? Envelope::create($message))->getRecipients();
        if (count($recipients) !== 1) {
            return null;
        }
        $this->renderer?->render($message);
        $html = $this->read($message->getHtmlBody());
        if (!is_string($html) || $html === '' || strlen($html) > $this->maxHtmlBytes || str_contains(strtolower($html), strtolower($this->pixelBase))) {
            return null;
        }

        try {
            $decision = $this->policy->decide(new TrackingCandidate(
                $template,
                strtolower($recipients[0]->getAddress()),
                MailHeaders::value($message, MailHeaders::RECIPIENT),
                MailHeaders::value($message, MailHeaders::TENANT),
            ));
        } catch (Throwable $exception) {
            $this->logger?->warning('Mail tracking policy failed: the e-mail is sent untracked.', ['bundle' => 'NowoMailKitBundle', 'error' => $exception::class]);

            return null;
        }
        if (!$decision->tracks()) {
            return null;
        }
        try {
            $signer = $this->signer instanceof Closure ? ($this->signer)() : $this->signer;
        } catch (Throwable $exception) {
            $this->logger?->critical('Mail tracking signer unavailable (check nowo_mail_kit.tracking.secret): the e-mail is sent untracked.', ['bundle' => 'NowoMailKitBundle', 'error' => $exception::class]);

            return null;
        }
        $sendId = TrackingTokenSigner::newSendId();
        if (!$this->store->createSend($sendId, TrackingIds::template($template), $decision->recipientId, $decision->tenant, $logId)) {
            return null;
        }

        $copy = clone $message;
        if ($decision->links) {
            // @igor-ignore - The closure is an argument of a pure call, never stored: it lives only in this send.
            $result = $this->html->rewriteHtml($html, function (string $url, ?string $label, int $position) use ($sendId, $template, $decision, $secrets): ?string {
                return $this->rewrite(new TrackedLink($sendId, $url, $label, $position, $this->essential($url, $label), $template, $decision->recipientId, $decision->tenant), $secrets);
            });
            $html = $result['html'];
            $text = $result['map'] === [] ? null : $this->read($message->getTextBody());
            if (is_string($text)) {
                $copy->text($this->html->rewriteText($text, $result['map']), $message->getTextCharset() ?? 'utf-8');
            }
        }
        if ($decision->pixel) {
            $html = $this->html->injectPixel($html, $this->pixelBase . '/' . $signer->issue($sendId) . '.gif');
        }
        $copy->html($html, $message->getHtmlCharset() ?? 'utf-8');

        return new TrackedEmail($copy, $sendId);
    }

    /**
     * Tracking URL of one link, or null to keep it.
     *
     * @param list<string> $secrets
     */
    private function rewrite(TrackedLink $link, array $secrets): ?string
    {
        if (str_starts_with(strtolower($link->url), strtolower($this->pixelBase)) || preg_match('/[\x00-\x20\x7F]/', $link->url) === 1 || $this->credentials->isCredential($link->url, $secrets)) {
            return null;
        }
        try {
            if (!$this->filter->mayRewrite($link->url, $link->templateId)) {
                return null;
            }
            $new = $this->rewriter->rewrite($link);
        } catch (Throwable $exception) {
            $this->logger?->warning('Mail link rewriter failed: the link is kept.', ['bundle' => 'NowoMailKitBundle', 'error' => $exception::class]);

            return null;
        }

        return $new !== null && $this->acceptable($new) ? $new : null;
    }

    /**
     * A body as a string (a stream is read from its start).
     *
     * @param resource|string|null $body
     */
    private function read(mixed $body): string|false|null
    {
        if (!is_resource($body)) {
            return is_string($body) ? $body : null;
        }
        if (stream_get_meta_data($body)['seekable']) {
            rewind($body);
        }

        return stream_get_contents($body);
    }

    private function acceptable(string $url): bool
    {
        if (mb_strlen($url) > self::MAX_URL || preg_match('~^https?://[^\s/?#]+~i', $url) !== 1 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '';
    }

    private function essential(string $url, ?string $label): bool
    {
        $haystack = strtolower($url . ' ' . $label);
        foreach ($this->essentialPatterns as $pattern) {
            if (str_contains($haystack, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    private function neverTracked(?string $template): bool
    {
        if ($template === null) {
            return false;
        }
        foreach ([...self::SECURITY_TEMPLATES, ...$this->neverTrackTemplates] as $pattern) {
            if (fnmatch($pattern, $template, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }
}
