<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Sender;

use Nowo\MailKitBundle\DependencyInjection\Compiler\SenderPortPass;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;

use function get_debug_type;

/**
 * Serves both sender ports from the host sender service, whichever of the two it implements (1.12): the
 * framework-free {@see SenderIdentityProviderInterface} or the deprecated {@see SenderProviderInterface}.
 *
 * {@see SenderPortPass} puts this gate behind `SenderProviderInterface` when the service configured in
 * `nowo_mail_kit.mailer.sender_provider` implements the new port (alone or next to the deprecated one) or when its
 * class cannot be known before the container is compiled, so {@see SenderPolicy}, the test mail and every decorator of
 * the deprecated id keep working unchanged; and behind `SenderIdentityProviderInterface` when the service implements
 * only the deprecated port, so the new port can always be autowired. When the host decorates the sender, a gate over
 * the decorated service also serves the new port where a decorator implements only the deprecated one (1.12.1) and
 * MailKit's own mail path (1.12.2), so a decorator's `defaultFrom()` / `defaultReplyTo()` win there too.
 *
 * Precedence: when the service implements both ports, the new one wins — every method here reads `defaultFrom()` /
 * `defaultReplyTo()` and the deprecated `from()` / `replyTo()` of that service are never asked.
 *
 * An address of the new port that the mailer refuses is left out of the message, the same leniency as the settings
 * sender (1.12.1, PI-L1): the gate then logs {@see self::REFUSED_ADDRESS_MESSAGE} as a warning with the provider class
 * and the field only — never the address, which may be personal data.
 *
 * Stateless: the port is read on every call (REQ-RUNTIME-002).
 *
 * @internal
 */
final readonly class SenderProviderGate implements SenderIdentityProviderInterface, SenderProviderInterface
{
    /** Constant warning logged when the mailer refuses an address of the new port; it never holds the address. */
    public const REFUSED_ADDRESS_MESSAGE = 'MailKit: the sender provider returned an address the mailer refuses; the header is left out.';

    /**
     * @param SenderIdentityProviderInterface|SenderProviderInterface $provider the host sender service
     * @param LoggerInterface|null $logger receives the refused-address warning
     */
    public function __construct(
        private SenderIdentityProviderInterface|SenderProviderInterface $provider,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Default `From` of the host port, or null for none.
     */
    public function from(): ?Address
    {
        return $this->provider instanceof SenderIdentityProviderInterface
            ? $this->toMime($this->provider->defaultFrom(), 'from')
            : $this->provider->from();
    }

    /**
     * Default `Reply-To` of the host port, or null for none.
     */
    public function replyTo(): ?Address
    {
        return $this->provider instanceof SenderIdentityProviderInterface
            ? $this->toMime($this->provider->defaultReplyTo(), 'replyTo')
            : $this->provider->replyTo();
    }

    /**
     * Default `From` as the framework-free value object, or null for none.
     */
    public function defaultFrom(): ?MailAddress
    {
        return $this->provider instanceof SenderIdentityProviderInterface
            ? $this->provider->defaultFrom()
            : MimeAddressMapper::toValueObject($this->provider->from());
    }

    /**
     * Default `Reply-To` as the framework-free value object, or null for none.
     */
    public function defaultReplyTo(): ?MailAddress
    {
        return $this->provider instanceof SenderIdentityProviderInterface
            ? $this->provider->defaultReplyTo()
            : MimeAddressMapper::toValueObject($this->provider->replyTo());
    }

    /**
     * The Mime address of a new-port value, with a warning (no address in it) when the mailer refuses a non-null one.
     *
     * @param 'from'|'replyTo' $field
     */
    private function toMime(?MailAddress $address, string $field): ?Address
    {
        $mime = MimeAddressMapper::toMimeAddress($address);
        if ($address instanceof MailAddress && !$mime instanceof Address) {
            $this->logger?->warning(self::REFUSED_ADDRESS_MESSAGE, ['provider' => get_debug_type($this->provider), 'field' => $field]);
        }

        return $mime;
    }
}
