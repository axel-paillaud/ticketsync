<?php

namespace App\Doctrine\Middleware;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * SQLite does not enforce foreign keys unless "PRAGMA foreign_keys=ON" is
 * executed on every new connection. Without it, ON DELETE rules are ignored
 * and deleting a row silently leaves orphans behind.
 *
 * The pragma is only sent to SQLite connections, so switching to another
 * database engine later does not require removing this middleware.
 */
#[AsMiddleware]
final class SqliteForeignKeysMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(#[SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);

                if (str_contains((string) ($params['driver'] ?? ''), 'sqlite')) {
                    $connection->exec('PRAGMA foreign_keys = ON');
                }

                return $connection;
            }
        };
    }
}
