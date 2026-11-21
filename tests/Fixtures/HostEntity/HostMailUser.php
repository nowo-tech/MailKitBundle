<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Fixtures\HostEntity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\MailKitBundle\Entity\SentMail;

/**
 * A host application entity on the host's (default) entity manager that points at a MailKit log row (ManyToOne), as a
 * host may do to show "the last mail we sent you" (host-entity tests).
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_mail_user')]
class HostMailUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.onlyRead (assigned by Doctrine on flush)
    private int $id;

    #[ORM\ManyToOne(targetEntity: SentMail::class)]
    #[ORM\JoinColumn(name: 'last_mail_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SentMail $lastMail = null;

    public function __construct(
        #[ORM\Column(length: 190)]
        private string $email,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getLastMail(): ?SentMail
    {
        return $this->lastMail;
    }

    public function setLastMail(?SentMail $lastMail): void
    {
        $this->lastMail = $lastMail;
    }
}
