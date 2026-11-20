<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Sender;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

use function get_debug_type;

/**
 * Fills the runtime sender of outgoing messages: `From` and `Reply-To` from the {@see SenderIdentityProviderInterface}
 * (by default the settings `mailer.from` / `mailer.from_name`, or `mailer.resend_from` when Resend is active, and
 * `mailer.reply_to`) — only when the message has none, so code that sets its own sender keeps it.
 *
 * An address the mailer refuses is left out of the message (the same leniency as the settings sender): the policy then
 * logs {@see self::REFUSED_ADDRESS_MESSAGE} as a warning with the provider class and the field only — never the
 * address, which may be personal data.
 *
 * Stateless: the port is read on every call (REQ-RUNTIME-002).
 */
final readonly class SenderPolicy
{
    /** Constant warning logged when the mailer refuses an address of the sender port; it never holds the address. */
    public const REFUSED_ADDRESS_MESSAGE = 'MailKit: the sender provider returned an address the mailer refuses; the header is left out.';

    /**
     * @param SenderIdentityProviderInterface $sender default sender (the mail settings unless `mailer.sender_provider` is set)
     * @param LoggerInterface|null $logger receives the refused-address warning
     */
    public function __construct(
        private SenderIdentityProviderInterface $sender,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Adds the missing From / Reply-To headers in place (raw, non-MIME messages are left untouched).
     */
    public function apply(RawMessage $message): void
    {
        if (!$message instanceof Message) {
            return;
        }
        $headers = $message->getHeaders();
        if (!$headers->has('From')) {
            $from = self::toMime($this->sender, $this->sender->defaultFrom(), 'from', $this->logger);
            if ($from instanceof Address) {
                $headers->addMailboxListHeader('From', [$from]);
            }
        }
        if (!$headers->has('Reply-To')) {
            $replyTo = self::toMime($this->sender, $this->sender->defaultReplyTo(), 'replyTo', $this->logger);
            if ($replyTo instanceof Address) {
                $headers->addMailboxListHeader('Reply-To', [$replyTo]);
            }
        }
    }

    /**
     * The Mime address of a sender-port value, or null for none — and null, with a warning (no address in it), when
     * the mailer refuses a non-null one.
     *
     * @param SenderIdentityProviderInterface $provider the port the value came from (its class is logged)
     * @param 'from'|'replyTo' $field
     */
    public static function toMime(SenderIdentityProviderInterface $provider, ?MailAddress $address, string $field, ?LoggerInterface $logger): ?Address
    {
        $mime = MimeAddressMapper::toMimeAddress($address);
        if ($address instanceof MailAddress && !$mime instanceof Address) {
            $logger?->warning(self::REFUSED_ADDRESS_MESSAGE, ['provider' => get_debug_type($provider), 'field' => $field]);
        }

        return $mime;
    }
}
