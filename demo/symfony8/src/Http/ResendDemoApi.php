<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpClient\Response\JsonMockResponse;

use function is_array;
use function is_string;
use function str_starts_with;

/**
 * Demo only: offline stand-in for `GET https://api.resend.com/domains` (the MailKit Resend key check). A bearer key
 * starting with `re_demo_` is valid and lists one verified domain; any other key is rejected like Resend does.
 */
final class ResendDemoApi
{
    /** Prefix of the keys the demo accepts. */
    public const VALID_PREFIX = 're_demo_';

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): JsonMockResponse
    {
        $headers = $options['normalized_headers']['authorization'] ?? [];
        $header  = is_array($headers) && is_string($headers[0] ?? null) ? $headers[0] : '';

        return str_starts_with($header, 'Authorization: Bearer ' . self::VALID_PREFIX)
            ? new JsonMockResponse(['data' => [['name' => 'example.com', 'status' => 'verified']]])
            : new JsonMockResponse(['name' => 'validation_error', 'message' => 'API key is invalid'], ['http_code' => 401]);
    }
}
