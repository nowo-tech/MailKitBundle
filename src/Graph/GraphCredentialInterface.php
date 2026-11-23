<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph;

/**
 * Token source of one {@see GraphApiClient} call sequence (2.5.0): app-only client credentials of a shared mailbox
 * profile ({@see AppOnlyGraphCredential}) or a delegated mailbox's token from the host port. The client asks for the
 * token before every call, reports a 401 once (then retries with a fresh token) and reports every HTTP call.
 *
 * @internal
 */
interface GraphCredentialInterface
{
    /**
     * Bearer token for the next call (never logged).
     *
     * @throws GraphException
     */
    public function token(): string;

    /**
     * Graph answered 401 to the last token: the next token() must be fresh.
     */
    public function rejected(): void;

    /**
     * Log context naming the caller (a profile or a mailbox id; never a secret or an address).
     *
     * @return array<string, string>
     */
    public function logContext(): array;

    /**
     * One HTTP call ended (status 0 = transport error), for metering.
     *
     * @param string $operation fixed operation label of the call
     * @param int|null $retryAfter seconds from Retry-After
     * @param int $attempt 1 for the first try
     */
    public function called(string $operation, int $status, int $durationMs, int $bytes, ?int $retryAfter, int $attempt): void;
}
