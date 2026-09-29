<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conventions;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TestClassesExtendKernelTestCaseTest extends KernelTestCase
{
    #[TestDox('Every test class extends KernelTestCase directly or through WebTestCase or PantherTestCase')]
    public function testEveryTestClassExtendsKernelTestCase(): void
    {
        $violations = [];
        foreach (ProjectFiles::testClasses() as $path => $class) {
            if (null === $class) {
                $violations[] = $path.': does not declare its PSR-4 class';
            } elseif (!is_subclass_of($class, KernelTestCase::class)) {
                $violations[] = $path.': '.$class.' does not extend KernelTestCase';
            }
        }

        self::assertSame([], $violations);
    }
}
