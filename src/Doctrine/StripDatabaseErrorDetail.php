<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query;

/**
 * Keeps the first line of a driver error only, because PostgreSQL adds DETAIL lines with row values. The replacement
 * has no previous exception, because Monolog logs the message of each previous exception.
 */
final class StripDatabaseErrorDetail implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware implements ExceptionConverter {
            public function getExceptionConverter(): ExceptionConverter
            {
                return $this;
            }

            public function convert(Exception $exception, ?Query $query): DriverException
            {
                $firstLine = explode("\n", $exception->getMessage(), 2)[0];
                $withoutDetail = new class($firstLine, $exception->getSQLState(), $exception->getCode()) extends AbstractException {
                };

                return parent::getExceptionConverter()->convert($withoutDetail, $query);
            }
        };
    }
}
