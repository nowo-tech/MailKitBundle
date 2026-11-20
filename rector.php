<?php

declare(strict_types=1);

/**
 * Rector configuration for Mail Kit Bundle.
 *
 * @see https://getrector.com/documentation
 */
use Rector\Config\RectorConfig;
use Rector\Symfony\Symfony72\Rector\StmtsAwareInterface\PushRequestToRequestStackConstructorRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withComposerBased(symfony: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        // The worker-request tests push / pop requests on one stack on purpose.
        PushRequestToRequestStackConstructorRector::class => [__DIR__ . '/tests'],
        // 1.x host classes on the sender port removed in 2.0 (build-error tests): the interface no longer exists.
        __DIR__ . '/tests/Fixtures/RemovedPort',
        __DIR__ . '/demo',
        __DIR__ . '/vendor',
    ]);
