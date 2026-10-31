<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * `http_client` of the Resend functional test: the answers of four key checks, in order.
 */
final class ResendApiMock
{
    public static function create(): MockHttpClient
    {
        return new MockHttpClient([
            new JsonMockResponse(['data' => [['name' => 'example.com', 'status' => 'verified'], ['name' => 'x']]]),
            new JsonMockResponse(['name' => 'restricted_api_key', 'message' => 'restricted'], ['http_code' => 401]),
            new JsonMockResponse(['data' => []]),
            new JsonMockResponse(['name' => 'validation_error', 'message' => 'API key is invalid'], ['http_code' => 400]),
        ]);
    }
}
