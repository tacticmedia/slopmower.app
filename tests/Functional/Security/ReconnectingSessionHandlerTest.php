<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Security\ReconnectingSessionHandler;
use App\Tests\Support\Database\ResetDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;

final class ReconnectingSessionHandlerTest extends KernelTestCase
{
    use ResetDatabase;

    private const string SESSION_ID = 'k4Vq9ZrT2bXw7LmN3pYs8GdH';

    #[TestDox('After the database ends the connection, one session request fails and the next one reads the session')]
    public function testNextSessionRequestReconnectsAfterALostConnection(): void
    {
        $handler = self::getContainer()->get(ReconnectingSessionHandler::class);
        self::request($handler, static fn (): bool => $handler->write(self::SESSION_ID, 'alice'));

        $pdo = self::getContainer()->get(Connection::class)->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $pdo);
        try {
            // Through PDO, so DBAL does not see the lost connection.
            $pdo->exec('SELECT pg_terminate_backend(pg_backend_pid())');
        } catch (\PDOException) {
        }

        try {
            self::request($handler, static fn (): string => $handler->read(self::SESSION_ID));
            self::fail('The first session request after the lost connection did not fail.');
        } catch (\PDOException) {
        }

        self::assertSame('alice', self::request($handler, static fn (): string => $handler->read(self::SESSION_ID)));
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private static function request(ReconnectingSessionHandler $handler, \Closure $operation): mixed
    {
        try {
            $handler->open('', 'PHPSESSID');
            $result = $operation();
            $handler->close();

            return $result;
        } finally {
            self::getContainer()->get(ServicesResetterInterface::class)->reset();
        }
    }
}
