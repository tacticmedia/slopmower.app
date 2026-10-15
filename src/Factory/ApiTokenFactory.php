<?php

declare(strict_types=1);

namespace App\Factory;

use App\Domain\ApiToken\ApiTokenSecret;
use App\Entity\ApiToken;
use Symfony\Component\Clock\Clock;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<ApiToken>
 */
final class ApiTokenFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return ApiToken::class;
    }

    protected function defaults(): array
    {
        return [
            'user' => UserFactory::new(),
            'name' => 'Claude Code',
            'repository' => null,
            'secret' => ApiTokenSecret::generate(),
            'createdAt' => Clock::get()->now(),
            'expiresAt' => null,
        ];
    }
}
