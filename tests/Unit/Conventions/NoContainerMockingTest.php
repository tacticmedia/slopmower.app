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
            if (self::replacesService($file->getContents())) {
                $violations[] = $path;
            }
        }

        self::assertSame([], $violations);
    }

    private static function replacesService(string $code): bool
    {
        if (1 === preg_match('/getContainer\(\)\s*->\s*set\(/', $code)) {
            return true;
        }

        preg_match_all('/\$(\w+)\s*=\s*(?:self|static|parent|\$this)\s*(?:::|->)\s*getContainer\(\)\s*;/', $code, $matches);
        foreach (array_unique($matches[1]) as $variable) {
            if (1 === preg_match('/\$'.$variable.'\s*->\s*set\(/', $code)) {
                return true;
            }
        }

        return false;
    }
}
