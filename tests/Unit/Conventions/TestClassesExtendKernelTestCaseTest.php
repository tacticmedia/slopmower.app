<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conventions;

use App\Tests\E2E\BrowserSmokeTest;
use App\Tests\Support\E2E\FullStackTestCase;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Finder\Finder;

final class TestClassesExtendKernelTestCaseTest extends KernelTestCase
{
    private const array ALLOWED_PARENTS = [KernelTestCase::class, WebTestCase::class, FullStackTestCase::class];

    #[TestDox('Every test class extends KernelTestCase, WebTestCase, or FullStackTestCase directly')]
    public function testEveryTestClassExtendsAnAllowedBase(): void
    {
        $violations = [];

        foreach (Finder::create()->files()->in(dirname(__DIR__, 2))->name('*Test.php') as $file) {
            $class = 'App\\Tests\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (!self::hasAllowedParent(class_parents($class))) {
                $violations[] = $class;
            }
        }

        self::assertSame([], $violations);
    }

    #[TestDox('The rule accepts a FullStackTestCase subclass and rejects a PantherTestCase subclass')]
    public function testRuleAcceptsFullStackTestCaseAndRejectsPantherTestCase(): void
    {
        self::assertTrue(self::hasAllowedParent(class_parents(BrowserSmokeTest::class)));
        self::assertFalse(self::hasAllowedParent(['Symfony\Component\Panther\PantherTestCase', WebTestCase::class, KernelTestCase::class, TestCase::class]));
    }

    /**
     * @param array<string>|false $parents Nearest first, as class_parents() returns them
     */
    private static function hasAllowedParent(array|false $parents): bool
    {
        return false !== $parents && \in_array(array_values($parents)[0] ?? null, self::ALLOWED_PARENTS, true);
    }
}
