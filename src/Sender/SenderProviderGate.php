<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Sender;

use Nowo\MailKitBundle\DependencyInjection\Compiler\SenderPortPass;
use Symfony\Component\Mime\Address;

/**
 * Asks the host sender service for the default `From` / `Reply-To`, whichever of the two ports it implements (1.12):
 * the framework-free {@see SenderIdentityProviderInterface} or the deprecated {@see SenderProviderInterface}.
 *
 * {@see SenderPortPass} puts this gate behind `SenderProviderInterface` when the service configured in
 * `nowo_mail_kit.mailer.sender_provider` implements only the new port (or when its class cannot be known before the
 * container is compiled), so {@see SenderPolicy}, the test mail and every decorator of the deprecated id keep working
 * unchanged. A service that implements the deprecated port is asked directly, without this gate, until 2.0.
 *
 * Stateless: the port is read on every call (REQ-RUNTIME-002).
 *
 * @internal
 */
final readonly class SenderProviderGate implements SenderProviderInterface
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
        return $this->provider instanceof SenderProviderInterface
            ? $this->provider->from()
            : MimeAddressMapper::toMimeAddress($this->provider->defaultFrom());
    }

    /**
     * Default `Reply-To` of the host port, or null for none.
     */
    public function replyTo(): ?Address
    {
        return $this->provider instanceof SenderProviderInterface
            ? $this->provider->replyTo()
            : MimeAddressMapper::toMimeAddress($this->provider->defaultReplyTo());
    }
}
