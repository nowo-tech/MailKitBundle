<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Host user with an identifier and an e-mail address (UserKit bridge tests).
 */
final class HostUser implements UserInterface
{
    public function __construct(
        private readonly string $identifier,
        private readonly ?string $email,
    ) {
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier !== '' ? $this->identifier : 'anonymous';
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
