<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;
use Symfony\Bridge\Doctrine\SchemaListener\PdoSessionHandlerSchemaListener;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A {@see PdoSessionHandler} on the native connection of Doctrine, so it follows the database suffix of test and e2e.
 * A new handler per request takes the current native connection, so a worker recovers after a lost connection.
 * Advisory locks, because the default transactional lock holds a transaction on the shared connection from session start to save.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class ReconnectingSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface, ResetInterface
{
    private ?PdoSessionHandler $handler = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function open(string $path, string $name): bool
    {
        return $this->call(static fn (PdoSessionHandler $handler): bool => $handler->open($path, $name));
    }

    public function close(): bool
    {
        return $this->call(static fn (PdoSessionHandler $handler): bool => $handler->close());
    }

    public function read(#[\SensitiveParameter] string $id): string
    {
        return $this->call(static fn (PdoSessionHandler $handler): string => $handler->read($id));
    }

    public function write(#[\SensitiveParameter] string $id, string $data): bool
    {
        return $this->call(static fn (PdoSessionHandler $handler): bool => $handler->write($id, $data));
    }

    public function destroy(#[\SensitiveParameter] string $id): bool
    {
        return $this->call(static fn (PdoSessionHandler $handler): bool => $handler->destroy($id));
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->call(static fn (PdoSessionHandler $handler): int|false => $handler->gc($max_lifetime));
    }

    public function validateId(#[\SensitiveParameter] string $id): bool
    {
        return $this->call(static fn (PdoSessionHandler $handler): bool => $handler->validateId($id));
    }

    public function updateTimestamp(#[\SensitiveParameter] string $id, string $data): bool
    {
        return $this->call(static fn (PdoSessionHandler $handler): bool => $handler->updateTimestamp($id, $data));
    }

    public function reset(): void
    {
        $this->handler = null;
    }

    /**
     * The session handler service is not a {@see PdoSessionHandler}, so the listener of DoctrineBundle does not add the sessions table.
     */
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        new PdoSessionHandlerSchemaListener($this->handler())->postGenerateSchema($event);
    }

    /**
     * @template T
     *
     * @param \Closure(PdoSessionHandler): T $operation
     *
     * @return T
     */
    private function call(\Closure $operation): mixed
    {
        try {
            return $operation($this->handler());
        } catch (\PDOException $exception) {
            $this->reset();
            $this->connection->close();

            throw $exception;
        }
    }

    private function handler(): PdoSessionHandler
    {
        if (null === $this->handler) {
            $pdo = $this->connection->getNativeConnection();
            if (!$pdo instanceof \PDO) {
                throw new \LogicException(\sprintf('The session handler needs a PDO connection, got "%s".', get_debug_type($pdo)));
            }
            $this->handler = new PdoSessionHandler($pdo, ['lock_mode' => PdoSessionHandler::LOCK_ADVISORY]);
        }

        return $this->handler;
    }
}
