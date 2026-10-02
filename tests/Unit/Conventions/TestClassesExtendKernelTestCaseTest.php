<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conventions;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

final class TestClassesExtendKernelTestCaseTest extends KernelTestCase
{
    #[TestDox('Every test class extends KernelTestCase directly or through WebTestCase or PantherTestCase')]
    public function testEveryTestClassExtendsKernelTestCase(): void
    {
        $violations = [];

        foreach (Finder::create()->files()->in(dirname(__DIR__, 2))->name('*Test.php') as $file) {
            $class = 'App\\Tests\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (!is_subclass_of($class, KernelTestCase::class)) {
                $violations[] = $class;
            }
        }

        self::assertSame([], $violations);
    }
}
