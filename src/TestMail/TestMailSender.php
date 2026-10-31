<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\TestMail;

use Nowo\MailKitBundle\Dsn\RuntimeDsn;
use Nowo\MailKitBundle\Log\ErrorScrubber;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\MailKitBundle\Mailer\RuntimeTransport;
use Nowo\MailKitBundle\NowoMailKitBundle;
use Nowo\MailKitBundle\Sender\SenderAddress;
use Nowo\MailKitBundle\Settings\MailSettings;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function filter_var;
use function htmlspecialchars;
use function is_string;
use function method_exists;
use function nl2br;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;
use const FILTER_VALIDATE_EMAIL;

/**
 * Sends the test e-mail of the settings actions and the CLI, synchronously (never queued: the operator wants the
 * transport answer now) through the {@see RuntimeTransport} — the current runtime DSN (or framework transport) for
 * the mailer test, the stored Resend key for the Resend test. Tagged `mail_kit_test` in the sent-mail log.
 *
 * Recipient: the explicit address, else `mailer.test_recipient`, else the e-mail of the user (a `getEmail()`
 * method, or an identifier that is an address).
 */
final readonly class TestMailSender
{
    /** Log tag of the test e-mails. */
    public const TAG = 'mail_kit_test';

    /**
     * @param bool $requirePublicDomain `sender.require_public_domain`
     */
    public function __construct(
        private RuntimeTransport $transport,
        private MailSettings $settings,
        private ?TranslatorInterface $translator = null,
        private bool $requirePublicDomain = true,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Test of the current mailer configuration.
     */
    public function sendMailer(?string $to = null, ?object $user = null): TestMailResult
    {
        return $this->send('mailer', $this->settings->from(), $to, $user);
    }

    /**
     * Test of the stored Resend key (also while the Resend section is switched off).
     */
    public function sendResend(?string $to = null, ?object $user = null): TestMailResult
    {
        if ($this->settings->resendApiKey() === null) {
            return new TestMailResult(TestMailOutcome::NotConfigured);
        }

        return $this->send('resend', $this->settings->resendFrom(), $to, $user);
    }

    /**
     * Recipient of a test e-mail, or null.
     */
    public function recipient(?string $to, ?object $user): ?string
    {
        foreach ([$to, $this->settings->testRecipient(), $this->userEmail($user)] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return filter_var($candidate, FILTER_VALIDATE_EMAIL) === false ? null : $candidate;
            }
        }

        return null;
    }

    private function send(string $channel, ?Address $from, ?string $to, ?object $user): TestMailResult
    {
        $recipient = $this->recipient($to, $user);
        if ($recipient === null) {
            return new TestMailResult(TestMailOutcome::NoRecipient);
        }
        if (!$from instanceof Address) {
            return new TestMailResult(TestMailOutcome::NoSender, $recipient);
        }
        if ($this->requirePublicDomain && !SenderAddress::isDeliverable($from->getAddress())) {
            return new TestMailResult(TestMailOutcome::InvalidSender, $recipient);
        }

        $resend = $channel === 'resend' ? $this->settings->resendDsn() : null;
        $label  = $resend instanceof RuntimeDsn ? $resend->label() : $this->transport->currentLabel();
        $text   = $this->trans('test_email.body', ['%transport%' => $label, '%from%' => $from->getAddress()]);
        $email  = (new Email())
            ->from($from)
            ->to($recipient)
            ->subject($this->trans('test_email.subject'))
            ->text($text)
            ->html('<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5)) . '</p>');
        MailHeaders::tag($email, self::TAG);

        try {
            $resend instanceof RuntimeDsn ? $this->transport->sendVia($resend, $email) : $this->transport->send($email);
        } catch (Throwable $exception) {
            $this->logger?->warning('Test e-mail failed.', ['bundle' => 'NowoMailKitBundle', 'channel' => $channel, 'transport' => $label, 'error' => $exception::class]);

            return new TestMailResult(TestMailOutcome::Failed, $recipient, ErrorScrubber::scrub($exception->getMessage()));
        }

        $this->logger?->info('Test e-mail sent.', ['bundle' => 'NowoMailKitBundle', 'channel' => $channel, 'transport' => $label]);

        return new TestMailResult(TestMailOutcome::Sent, $recipient);
    }

    private function userEmail(?object $user): ?string
    {
        if ($user !== null && method_exists($user, 'getEmail')) {
            $email = $user->getEmail();
            if (is_string($email)) {
                return $email;
            }
        }
        if ($user !== null && method_exists($user, 'getUserIdentifier')) {
            $identifier = $user->getUserIdentifier();
            if (is_string($identifier) && filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false) {
                return $identifier;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator?->trans($key, $parameters, NowoMailKitBundle::TRANSLATION_DOMAIN) ?? $key;
    }
}
