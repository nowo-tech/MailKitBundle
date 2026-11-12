<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\MailKitBundle\Graph\Inbound\GraphInboundHandlerInterface;
use Nowo\MailKitBundle\Graph\Inbound\GraphInboundMessage;
use Nowo\MailKitBundle\Graph\Inbound\InboundDecision;
use RuntimeException;

use function stream_get_contents;

/**
 * Host handler of the inbound tests: keeps each message and copies the accepted attachment bytes inside the call
 * (as a real host must); routes by plus-address tag, quarantines otherwise; can be told to fail.
 */
final class RecordingInboundHandler implements GraphInboundHandlerInterface
{
    /** @var list<GraphInboundMessage> */
    public array $messages = [];

    /** @var list<array<string, string>> attachment bytes by file name, per message */
    public array $bytes = [];

    public bool $fail = false;

    public function handle(GraphInboundMessage $message): InboundDecision
    {
        if ($this->fail) {
            throw new RuntimeException('Host storage unavailable.');
        }
        $this->messages[] = $message;
        $copies           = [];
        foreach ($message->attachments as $attachment) {
            if ($attachment->isAccepted()) {
                $copies[$attachment->filename] = (string) stream_get_contents($attachment->stream());
            }
        }
        $this->bytes[] = $copies;

        return $message->plusTags !== [] ? InboundDecision::route($message->profile . ':' . $message->plusTags[0]) : InboundDecision::quarantine('no_tag');
    }
}
