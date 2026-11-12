<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Transport;

use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\Send\GraphAttachment;
use Nowo\MailKitBundle\Graph\Send\GraphMailSender;
use Nowo\MailKitBundle\Graph\Send\GraphOutgoingMail;
use Nowo\MailKitBundle\Graph\Send\GraphRecipient;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface as MimeException;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Header\IdentificationHeader;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

use function array_key_exists;
use function array_values;
use function is_string;
use function preg_match;
use function preg_match_all;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function strtolower;
use function trim;

/**
 * Symfony Mailer transport `microsoft+graph://<profile>` (1.8): sends a Mailer `Email` through {@see GraphMailSender}
 * from the profile's shared mailbox, so standard hosts keep using `MailerInterface` (a named transport, `X-Transport`
 * header, or `MAILER_DSN`) and Messenger queues the serializable `Email`.
 *
 * Mapping: To / Cc / Reply-To / Subject / text / HTML / attachments (inline ones with their Content-ID) and the
 * `Message-ID`, `In-Reply-To` and `References` headers; the envelope recipients that are neither To nor Cc become Bcc.
 * The From address is always the mailbox (its display name comes from the first From). The returned `SentMessage`
 * carries Graph's internetMessageId as message id and the Graph id in its debug output. Other custom headers are not sent.
 */
final class GraphTransport extends AbstractTransport
{
    /**
     * @param string|null $profile mailbox profile (null = the provider's default)
     */
    public function __construct(
        private readonly GraphMailSender $sender,
        private readonly ?string $profile = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);
    }

    /**
     * `microsoft+graph://<profile>`.
     */
    public function __toString(): string
    {
        return GraphTransportFactory::SCHEME . '://' . ($this->profile ?? 'default');
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        if (!$original instanceof Message) {
            throw new TransportException('The microsoft+graph transport sends MIME e-mails only (not raw messages).');
        }

        try {
            $email = MessageConverter::toEmail($original);
        } catch (MimeException) {
            throw new TransportException('The microsoft+graph transport could not read this e-mail (unsupported MIME structure).');
        }

        try {
            $result = $this->sender->send($this->toGraph($email, $message->getEnvelope()->getRecipients()), $this->profile);
        } catch (GraphException $exception) {
            // GraphException messages are built by MailKit (no body, URL, token or secret): safe to pass on.
            throw new TransportException($exception->getMessage(), $exception->status ?? 0, $exception);
        }

        $message->setMessageId(trim($result->internetMessageId, '<>'));
        $message->appendDebug('Microsoft Graph message id: ' . $result->graphId . "\n");
    }

    /**
     * @param array<Address> $envelope
     */
    private function toGraph(Email $email, array $envelope): GraphOutgoingMail
    {
        $to     = $this->recipients($email->getTo());
        $cc     = $this->recipients($email->getCc());
        $listed = [];
        foreach ([...$to, ...$cc] as $recipient) {
            $listed[strtolower($recipient->address)] = true;
        }
        $bcc = [];
        foreach ($envelope as $address) {
            $key = strtolower($address->getAddress());
            if (!array_key_exists($key, $listed)) {
                $listed[$key] = true;
                $bcc[]        = new GraphRecipient($address->getAddress(), $address->getName() !== '' ? $address->getName() : null);
            }
        }

        $html        = $this->body($email->getHtmlBody());
        $attachments = [];
        foreach ($email->getAttachments() as $part) {
            $attachments[] = $this->attachment($part, (string) $html);
        }
        $headers = $email->getHeaders();
        $from    = $email->getFrom()[0] ?? null;

        return new GraphOutgoingMail(
            to: $to,
            subject: (string) $email->getSubject(),
            text: $this->body($email->getTextBody()),
            html: $html,
            cc: $cc,
            bcc: $bcc,
            replyTo: $this->recipients($email->getReplyTo()),
            attachments: $attachments,
            messageId: $this->ids($headers, 'Message-ID')[0] ?? null,
            inReplyTo: $this->ids($headers, 'In-Reply-To')[0] ?? null,
            references: $this->ids($headers, 'References'),
            fromName: $from instanceof Address && $from->getName() !== '' ? $from->getName() : null,
        );
    }

    /**
     * @param array<Address> $addresses
     *
     * @return list<GraphRecipient>
     */
    private function recipients(array $addresses): array
    {
        $list = [];
        foreach ($addresses as $address) {
            $list[] = new GraphRecipient($address->getAddress(), $address->getName() !== '' ? $address->getName() : null);
        }

        return $list;
    }

    /**
     * @param resource|string|null $body
     */
    private function body(mixed $body): ?string
    {
        if ($body === null || is_string($body)) {
            return $body;
        }
        rewind($body);

        return (string) stream_get_contents($body);
    }

    /**
     * Symfony matches `cid:` references of the HTML against the part's name, then its Content-ID: the Graph contentId
     * is the referenced one.
     */
    private function attachment(DataPart $part, string $html): GraphAttachment
    {
        $name = $part->getName();
        $cid  = $name !== null && str_contains($html, 'cid:' . $name) ? $name : ($part->hasContentId() ? $part->getContentId() : $name);
        if ($cid !== null && preg_match(GraphAttachment::CONTENT_ID_PATTERN, $cid) !== 1) {
            $cid = null;
        }
        $inline = $part->getDisposition() === 'inline' && $cid !== null;

        return GraphAttachment::fromString($part->getBody(), $part->getFilename(), $part->getContentType(), $inline, $inline ? $cid : null);
    }

    /**
     * Message ids of a header (identification header, or `<…>` tokens of a plain header).
     *
     * @return list<string>
     */
    private function ids(Headers $headers, string $name): array
    {
        $header = $headers->get($name);
        if ($header instanceof IdentificationHeader) {
            return array_values($header->getIds());
        }
        if (!$header instanceof HeaderInterface) {
            return [];
        }
        preg_match_all('/<([^<>\s]+)>/', $header->getBodyAsString(), $matches);

        return array_values($matches[1]);
    }
}
