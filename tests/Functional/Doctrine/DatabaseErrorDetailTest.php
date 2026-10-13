<?php

declare(strict_types=1);

namespace App\Tests\Functional\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DatabaseErrorDetailTest extends KernelTestCase
{
    private const string ROW_TEXT = 'row text of a comment';

    /**
     * @param class-string<DriverException> $exceptionClass
     */
    #[TestDox('A constraint violation keeps its class, SQLSTATE, and first line, and no message holds the row')]
    #[DataProvider('violations')]
    public function testViolationKeepsClassAndSqlStateWithoutRowText(string $insert, string $exceptionClass, string $sqlState): void
    {
        $connection = self::getContainer()->get(Connection::class);
        // No table of the schema has a check constraint. Kernel shutdown closes the session, which drops the table.
        $connection->executeStatement("CREATE TEMPORARY TABLE error_detail (body text NOT NULL UNIQUE CHECK (body <> ''), note text)");
        $connection->executeStatement('INSERT INTO error_detail (body) VALUES (?)', [self::ROW_TEXT]);

        try {
            $connection->executeStatement($insert, [self::ROW_TEXT]);
            self::fail('The insert did not violate a constraint.');
        } catch (DriverException $exception) {
            self::assertSame([$exceptionClass, $sqlState], [$exception::class, $exception->getSQLState()]);
            self::assertStringContainsString('"error_detail', $exception->getMessage());

            for ($link = $exception; null !== $link; $link = $link->getPrevious()) {
                self::assertStringNotContainsString(self::ROW_TEXT, $link->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string, class-string<DriverException>, string}>
     */
    public static function violations(): iterable
    {
        yield 'NOT NULL' => ['INSERT INTO error_detail (body, note) VALUES (NULL, ?)', NotNullConstraintViolationException::class, '23502'];
        yield 'check' => ["INSERT INTO error_detail (body, note) VALUES ('', ?)", DriverException::class, '23514'];
        yield 'unique' => ['INSERT INTO error_detail (body) VALUES (?)', UniqueConstraintViolationException::class, '23505'];
    }
}
