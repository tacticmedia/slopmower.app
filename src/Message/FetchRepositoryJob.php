<?php

declare(strict_types=1);

namespace App\Message;

use App\Application\FetchRepository;
use App\Domain\Identity\RepositoryId;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Handled by {@see FetchRepository::handleFetch()}.
 */
#[AsMessage('async')]
final readonly class FetchRepositoryJob
{
    public function __construct(public RepositoryId $repositoryId)
    {
    }
}
