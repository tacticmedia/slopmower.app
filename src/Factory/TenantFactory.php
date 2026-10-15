<?php

declare(strict_types=1);

namespace App\Factory;

use App\Domain\Identity\TenantId;
use App\Entity\Tenant;
use Symfony\Component\Clock\Clock;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Tenant>
 */
final class TenantFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Tenant::class;
    }

    protected function defaults(): array
    {
        return [
            'id' => TenantId::generate(),
            'createdAt' => Clock::get()->now(),
        ];
    }
}
