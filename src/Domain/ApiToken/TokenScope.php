<?php

declare(strict_types=1);

namespace App\Domain\ApiToken;

enum TokenScope: string
{
    case AllRepositories = 'all_repositories';
    case Repository = 'repository';
}
