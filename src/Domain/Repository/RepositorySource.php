<?php

declare(strict_types=1);

namespace App\Domain\Repository;

enum RepositorySource: string
{
    case Github = 'github';
    case Local = 'local';
}
