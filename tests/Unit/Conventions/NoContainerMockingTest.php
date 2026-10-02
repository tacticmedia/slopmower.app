<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conventions;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

final class NoContainerMockingTest extends KernelTestCase
{
    #[TestDox('No test replaces a service in the container')]
    public function testNoTestReplacesAContainerService(): void
    {
        $violations = [];

        foreach (Finder::create()->files()->in(dirname(__DIR__, 2))->name('*.php') as $file) {
            $contents = $file->getContents();
            preg_match_all('/(\$\w+)\s*=\s*[^;]*getContainer\(\)\s*;/', $contents, $assignments);
            $containerExpressions = ['getContainer\(\)', ...array_map(static fn (string $variable): string => preg_quote($variable, '/'), $assignments[1])];

            if (1 === preg_match('/(?:'.implode('|', $containerExpressions).')\s*->\s*set\(/', $contents)) {
                $violations[] = $file->getRelativePathname();
            }
        }

        self::assertSame([], $violations);
    }
}
