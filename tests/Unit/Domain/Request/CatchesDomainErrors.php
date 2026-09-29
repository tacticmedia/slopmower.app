<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Error\DomainError;

trait CatchesDomainErrors
{
    /**
     * @template T of DomainError
     *
     * @param class-string<T> $errorClass
     *
     * @return T
     */
    private static function domainError(string $errorClass, \Closure $action): DomainError
    {
        try {
            $action();
        } catch (DomainError $error) {
            self::assertInstanceOf($errorClass, $error, $error->getMessage());

            return $error;
        }

        self::fail($errorClass.' was not thrown.');
    }
}
