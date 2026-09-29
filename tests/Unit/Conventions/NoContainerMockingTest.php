<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conventions;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NoContainerMockingTest extends KernelTestCase
{
    #[TestDox('No test replaces a service in the container')]
    public function testNoTestReplacesAContainerService(): void
    {
        $violations = [];
        foreach (ProjectFiles::find(['tests'], ['*.php']) as $path => $file) {
            if (1 === preg_match('/getContainer\(\)\s*->\s*set\(/', $file->getContents())) {
                $violations[] = $path;
            }
        }

        self::assertSame([], $violations);
    }
}
