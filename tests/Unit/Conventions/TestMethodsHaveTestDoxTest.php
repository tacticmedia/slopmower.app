<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conventions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

final class TestMethodsHaveTestDoxTest extends KernelTestCase
{
    #[TestDox('Every test method has a TestDox attribute')]
    public function testEveryTestMethodHasTestDox(): void
    {
        $violations = [];

        foreach (Finder::create()->files()->in(dirname(__DIR__, 2))->name('*Test.php') as $file) {
            $class = 'App\\Tests\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (!class_exists($class)) {
                continue;
            }

            foreach (new \ReflectionClass($class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $isOwnTestMethod = str_starts_with($method->getDeclaringClass()->getName(), 'App\\Tests\\')
                    && (str_starts_with($method->getName(), 'test') || [] !== $method->getAttributes(Test::class));

                if ($isOwnTestMethod && [] === $method->getAttributes(TestDox::class)) {
                    $violations[] = $class.'::'.$method->getName();
                }
            }
        }

        self::assertSame([], $violations);
    }
}
