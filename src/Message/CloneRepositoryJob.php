<?php

declare(strict_types=1);

namespace App\Message;

use App\Domain\Identity\RepositoryId;
use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('async')]
final readonly class CloneRepositoryJob
{
    public function __construct(public RepositoryId $repositoryId)
    {
    }
}
