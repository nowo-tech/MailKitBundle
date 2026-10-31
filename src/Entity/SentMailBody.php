<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\MailKitBundle\Mailer\MailHeaders;

/**
 * Stored bodies of one {@see SentMail} row (same id), written only when `log.store_bodies` is on. Values declared
 * through {@see MailHeaders::redact()} are masked. Table `<prefix>mail_kit_sent_mail_body`.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: self::TABLE)]
class SentMailBody
{
    /** Unprefixed table name. */
    public const TABLE = 'mail_kit_sent_mail_body';

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'html_body', type: Types::TEXT, length: 16_777_215, nullable: true)]
    private ?string $htmlBody;

    #[ORM\Column(name: 'text_body', type: Types::TEXT, length: 16_777_215, nullable: true)]
    private ?string $textBody;

    /**
     * Rows are created by the recorder (DBAL); the constructor exists for fixtures and tests.
     */
    public function __construct(string $id, ?string $htmlBody = null, ?string $textBody = null)
    {
        $this->id       = $id;
        $this->htmlBody = $htmlBody;
        $this->textBody = $textBody;
    }

    /** Id of the {@see SentMail} row. */
    public function getId(): string
    {
        return $this->id;
    }

    /** HTML body (masked, capped at 1 MiB), or null. */
    public function getHtmlBody(): ?string
    {
        return $this->htmlBody;
    }

    /** Text body (masked, capped at 1 MiB), or null. */
    public function getTextBody(): ?string
    {
        return $this->textBody;
    }
}
