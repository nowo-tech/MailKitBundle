<?php

declare(strict_types=1);

namespace App\Tests\Outlook;

use App\Outlook\RawDoubleSendCheck;
use DateTimeImmutable;
use LogicException;
use Nowo\MailKitBundle\Mailbox\Port\MailboxTokenProviderInterface;
use Nowo\MailKitBundle\Mailbox\Value\MailboxAccessToken;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RawDoubleSendCheckTest extends TestCase
{
    public function testASecondSubmitRefusedBy4xxPasses(): void
    {
        $calls = [];
        $http  = $this->graph($calls, [
            new MockResponse('{"id":"AAMk-draft="}', ['http_code' => 201]),
            new MockResponse('', ['http_code' => 202]),
            new MockResponse('{"isDraft":false}'),
            new MockResponse('{"error":{"code":"ErrorInvalidOperation","message":"Already sent"}}', ['http_code' => 400]),
        ]);

        $result = $this->check($http)->run(new MailboxId('outlook-test'), 'you@example.com', 'subject');

        self::assertTrue($result['refused']);
        self::assertSame(202, $result['first_status']);
        self::assertFalse($result['is_draft_after_first']);
        self::assertSame(400, $result['second_status']);
        self::assertSame('ErrorInvalidOperation: Already sent', $result['second_error']);
        self::assertSame([
            'POST https://graph.microsoft.com/v1.0/me/messages',
            'POST https://graph.microsoft.com/v1.0/me/messages/AAMk-draft%3D/send',
            'GET https://graph.microsoft.com/v1.0/me/messages/AAMk-draft%3D?$select=isDraft',
            'POST https://graph.microsoft.com/v1.0/me/messages/AAMk-draft%3D/send',
        ], $calls);
    }

    public function testASecondSubmitAcceptedIsReported(): void
    {
        $calls = [];
        $http  = $this->graph($calls, [
            new MockResponse('{"id":"d1"}', ['http_code' => 201]),
            new MockResponse('', ['http_code' => 202]),
            new MockResponse('{"isDraft":false}'),
            new MockResponse('', ['http_code' => 202]),
        ]);

        $result = $this->check($http)->run(new MailboxId('outlook-test'), 'you@example.com', 'subject');

        self::assertFalse($result['refused']);
        self::assertSame(202, $result['second_status']);
    }

    /**
     * @param list<string> $calls
     * @param list<MockResponse> $responses
     */
    private function graph(array &$calls, array $responses): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls, &$responses): MockResponse {
            $calls[] = $method . ' ' . $url;
            self::assertContains('Authorization: Bearer token.value', $options['headers']);

            return array_shift($responses) ?? throw new LogicException('Unexpected call');
        });
    }

    private function check(MockHttpClient $http): RawDoubleSendCheck
    {
        $tokens = new class implements MailboxTokenProviderInterface {
            public function accessToken(MailboxId $mailbox): MailboxAccessToken
            {
                return new MailboxAccessToken('token.value', new DateTimeImmutable('+1 hour'));
            }

            public function rejected(MailboxId $mailbox, string $tokenFingerprint): void
            {
            }
        };

        return new RawDoubleSendCheck($http, $tokens, static function (int $microseconds): void {
        });
    }
}
