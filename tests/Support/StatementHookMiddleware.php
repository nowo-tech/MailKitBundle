<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Closure;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Statement;

/**
 * DBAL middleware that calls a hook with the SQL of every prepared statement before it runs (statements with
 * parameters: all the stores' writes). The hook may throw (an injected deadlock) or act on another connection (a
 * deterministic interleaving of two connections, REQ-RUNTIME-003 race tests). Same API on DBAL 3 and 4.
 */
final readonly class StatementHookMiddleware implements Middleware
{
    /**
     * @param Closure(string): void $hook
     */
    public function __construct(private Closure $hook)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->hook) extends AbstractDriverMiddleware {
            /**
             * @param Closure(string): void $hook
             */
            public function __construct(Driver $driver, private readonly Closure $hook)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): DriverConnection
            {
                return new class(parent::connect($params), $this->hook) extends AbstractConnectionMiddleware {
                    /**
                     * @param Closure(string): void $hook
                     */
                    public function __construct(DriverConnection $connection, private readonly Closure $hook)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        ($this->hook)($sql);

                        return parent::prepare($sql);
                    }
                };
            }
        };
    }
}
