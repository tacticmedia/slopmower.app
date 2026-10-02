<?php

declare(strict_types=1);

namespace App\Live;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\Exception\ExceptionInterface as MercureException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes one private update with IDs only. The write has committed already, so a failure is logged and not retried.
 */
final class LivePublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $data One member of live-update.schema.json
     */
    public function publish(string $topic, array $data, ?string $id = null): void
    {
        try {
            $this->hub->publish(new Update($topic, json_encode($data, \JSON_THROW_ON_ERROR), private: true, id: $id));
        } catch (MercureException $failure) {
            $this->logger->error('The live update to {topic} failed.', ['topic' => $topic, 'exception' => $failure]);
        }
    }
}
