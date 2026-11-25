<?php

declare(strict_types=1);

namespace App\Entity;

use App\Outlook\HarnessMailboxDirectory;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * DEMO ONLY (Outlook manual test harness): one delegated mailbox the harness registered. Mapped so that
 * `doctrine:schema:update` creates the table; read and written through DBAL by {@see HarnessMailboxDirectory}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'demo_outlook_mailbox')]
class OutlookMailbox
{
    #[ORM\Id]
    #[ORM\Column(length: 128)]
    public string $id = '';

    #[ORM\Column(length: 254)]
    public string $address = '';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    public array $folders = [];

    #[ORM\Column]
    public bool $paused = false;

    #[ORM\Column(name: 'initial_window', length: 32, nullable: true)]
    public ?string $initialWindow = null;
}
