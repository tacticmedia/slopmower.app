<?php

declare(strict_types=1);

namespace App\Factory;

use App\Domain\Identity\TenantId;
use App\Domain\Repository\RepositoryName;
use App\Entity\Repository;
use Symfony\Component\Clock\Clock;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A local repository without a clone, in a new tenant unless `tenantId` is given. The name is the directory.
 *
 * @extends PersistentObjectFactory<Repository>
 */
final class RepositoryFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Repository::class;
    }

    protected function defaults(): array
    {
        return [
            'tenantId' => LazyValue::new(static fn (): TenantId => TenantFactory::createOne()->getId()),
            'localDirectory' => self::faker()->unique()->slug(2),
            'createdAt' => Clock::get()->now(),
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->beforeInstantiate(static fn (array $parameters): array => $parameters + ['name' => RepositoryName::local($parameters['localDirectory'])])
            ->instantiateWith(Instantiator::namedConstructor('local'));
    }
}
