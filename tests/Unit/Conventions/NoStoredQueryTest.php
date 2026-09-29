<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conventions;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NativeQuery;
use Doctrine\ORM\Query;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A Query keeps the tenant constraint of its first parse, see "Doctrine Query objects" in docs/plan/README.md.
 */
final class NoStoredQueryTest extends KernelTestCase
{
    #[TestDox('No class in src has a property typed as a Doctrine query')]
    public function testNoClassInSrcStoresAQuery(): void
    {
        $violations = [];
        foreach (array_keys(ProjectFiles::find(['src'], ['*.php'])) as $path) {
            $class = 'App\\'.str_replace('/', '\\', substr($path, \strlen('src/'), -\strlen('.php')));
            if (class_exists($class) || trait_exists($class)) {
                array_push($violations, ...self::queryProperties(new \ReflectionClass($class)));
            }
        }

        self::assertSame([], $violations);
    }

    #[TestDox('Reports nullable, union, and promoted properties typed as a Doctrine query')]
    public function testReportsQueryProperties(): void
    {
        $class = new class(null) {
            /** @var Query<mixed, mixed>|null */
            public ?Query $nullable = null;
            /** @var AbstractQuery<mixed, mixed>|string */
            public AbstractQuery|string $union = '';
            public string $plain = '';

            public function __construct(public ?NativeQuery $promoted)
            {
            }
        };

        self::assertSame(
            [$class::class.'::$nullable', $class::class.'::$union', $class::class.'::$promoted'],
            self::queryProperties(new \ReflectionClass($class)),
        );
    }

    /**
     * @template T of object
     *
     * @param \ReflectionClass<T> $class
     *
     * @return list<string>
     */
    private static function queryProperties(\ReflectionClass $class): array
    {
        $properties = [];
        foreach ($class->getProperties() as $property) {
            if (self::namesAQuery($property->getType())) {
                $properties[] = $class->getName().'::$'.$property->getName();
            }
        }

        return $properties;
    }

    private static function namesAQuery(?\ReflectionType $type): bool
    {
        if ($type instanceof \ReflectionNamedType) {
            return !$type->isBuiltin() && is_a($type->getName(), AbstractQuery::class, true);
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if (self::namesAQuery($member)) {
                    return true;
                }
            }
        }

        return false;
    }
}
