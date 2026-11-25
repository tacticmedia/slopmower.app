<?php

declare(strict_types=1);

namespace App\Live;

use App\Application\Event\IssueChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class PublishIssueChanges
{
    public function __construct(
        private readonly LivePublisher $publisher,
        private readonly LiveTopics $topics,
    ) {
    }

    public function __invoke(IssueChanged $changed): void
    {
        $this->publisher->publish($this->topics->project($changed->tenantId, $changed->issueKey->projectKey), ['type' => 'issue', 'issueKey' => $changed->issueKey->toString()]);
    }
}
