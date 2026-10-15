<?php

declare(strict_types=1);

namespace App\Factory;

use App\Domain\Identity\TenantId;
use App\Entity\User;
use Symfony\Component\Clock\Clock;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A local user, in a new tenant unless `tenantId` is given.
 *
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return User::class;
    }

    protected function defaults(): array
    {
        return [
            'tenantId' => LazyValue::new(static fn (): TenantId => TenantFactory::createOne()->getId()),
            'login' => self::faker()->unique()->userName(),
            'signedInAt' => Clock::get()->now(),
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(Instantiator::namedConstructor('local'));
    }
}
