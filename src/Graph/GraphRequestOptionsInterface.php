<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph;

/**
 * Extra HttpClient options for a Graph request (2.5.0): the OutboundUrlGuard bridge pins the resolved public address
 * of the constant Graph host (`resolve`). Asked on every request; keeps nothing.
 *
 * @internal
 */
interface GraphRequestOptionsInterface
{
    /**
     * Options for a request to the URL (only `resolve` is used).
     *
     * @throws GraphException when the URL must not be called
     *
     * @return array{resolve?: array<string, string>}
     */
    public function options(string $url): array;
}
