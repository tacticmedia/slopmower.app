<?php

declare(strict_types=1);

namespace App\Tests\Support\Filesystem;

use PHPUnit\Framework\Attributes\After;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Directories under var/test/ that are removed after each test.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait TemporaryDirectories
{
    /**
     * @var list<string>
     */
    private array $pathsToRemove = [];

    private function createTemporaryDirectory(string $name = 'directory'): string
    {
        if (1 !== preg_match('/^[a-z0-9-]+$/D', $name)) {
            throw new \LogicException(\sprintf('"%s" is not a directory name of lower-case letters, digits, and dashes.', $name));
        }

        $directory = self::testDirectory().'/tmp/'.$name.'-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($directory);
        $this->pathsToRemove[] = $directory;

        return $directory;
    }

    /**
     * For paths that the code under test creates, for example a tenant directory.
     */
    private function removeAfterTest(string $path): void
    {
        $canonicalPath = Path::canonicalize($path);
        if ($canonicalPath === self::testDirectory() || !Path::isBasePath(self::testDirectory(), $canonicalPath)) {
            throw new \LogicException(\sprintf('"%s" is not under var/test/.', $path));
        }

        $this->pathsToRemove[] = $canonicalPath;
    }

    #[After]
    protected function removeTemporaryDirectories(): void
    {
        new Filesystem()->remove($this->pathsToRemove);
        $this->pathsToRemove = [];
    }

    private static function testDirectory(): string
    {
        return \dirname(__DIR__, 3).'/var/test';
    }
}
