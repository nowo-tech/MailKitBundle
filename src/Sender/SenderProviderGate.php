<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Sender;

use Nowo\MailKitBundle\DependencyInjection\Compiler\SenderPortPass;
use Symfony\Component\Mime\Address;

/**
 * Serves both sender ports from the host sender service, whichever of the two it implements (1.12): the
 * framework-free {@see SenderIdentityProviderInterface} or the deprecated {@see SenderProviderInterface}.
 *
 * {@see SenderPortPass} puts this gate behind `SenderProviderInterface` when the service configured in
 * `nowo_mail_kit.mailer.sender_provider` implements the new port (alone or next to the deprecated one) or when its
 * class cannot be known before the container is compiled, so {@see SenderPolicy}, the test mail and every decorator of
 * the deprecated id keep working unchanged; and behind `SenderIdentityProviderInterface` when the service implements
 * only the deprecated port, so the new port can always be autowired.
 *
 * Precedence: when the service implements both ports, the new one wins — every method here reads `defaultFrom()` /
 * `defaultReplyTo()` and the deprecated `from()` / `replyTo()` of that service are never asked.
 *
 * Stateless: the port is read on every call (REQ-RUNTIME-002).
 *
 * @internal
 */
final readonly class SenderProviderGate implements SenderIdentityProviderInterface, SenderProviderInterface
{
    /**
     * @param SenderIdentityProviderInterface|SenderProviderInterface $provider the host sender service
     */
    public function __construct(
        private SenderIdentityProviderInterface|SenderProviderInterface $provider,
    ) {
    }

    /**
     * Default `From` of the host port, or null for none.
     */
    public function from(): ?Address
    {
        return $this->provider instanceof SenderIdentityProviderInterface
            ? MimeAddressMapper::toMimeAddress($this->provider->defaultFrom())
            : $this->provider->from();
    }

    /**
     * Default `Reply-To` of the host port, or null for none.
     */
    public function replyTo(): ?Address
    {
        return $this->provider instanceof SenderIdentityProviderInterface
            ? MimeAddressMapper::toMimeAddress($this->provider->defaultReplyTo())
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
}
