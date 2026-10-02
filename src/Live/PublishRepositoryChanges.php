<?php

declare(strict_types=1);

namespace App\Live;

use App\Application\Event\RepositoryChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class PublishRepositoryChanges
{
    public function __construct(
        private readonly LivePublisher $publisher,
        private readonly LiveTopics $topics,
    ) {
    }

    public function __invoke(RepositoryChanged $changed): void
    {
        $this->publisher->publish($this->topics->repositories($changed->tenantId), ['type' => 'repository', 'repositoryId' => $changed->repositoryId->toString()]);
    }
}
