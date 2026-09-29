<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conventions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TestMethodsHaveTestDoxTest extends KernelTestCase
{
    #[TestDox('Every test method has a TestDox attribute')]
    public function testEveryTestMethodHasTestDox(): void
    {
        $violations = [];
        foreach (array_filter(ProjectFiles::testClasses()) as $class) {
            foreach (new \ReflectionClass($class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (self::isOurTestMethod($method) && [] === $method->getAttributes(TestDox::class)) {
                    $violations[] = $class.'::'.$method->getName();
                }
            }
        }

        self::assertSame([], $violations);
    }

    private static function isOurTestMethod(\ReflectionMethod $method): bool
    {
        return str_starts_with($method->getDeclaringClass()->getName(), 'App\\Tests\\')
            && !$method->isStatic()
            && (str_starts_with($method->getName(), 'test') || [] !== $method->getAttributes(Test::class));
    }
}
