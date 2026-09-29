<?php

declare(strict_types=1);

namespace App\Tests\Functional\Filesystem;

use App\Tests\Support\Filesystem\TemporaryDirectories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class TemporaryDirectoriesTest extends KernelTestCase
{
    use TemporaryDirectories;

    #[TestDox('Removes the created directories and the registered paths after the test')]
    public function testRemovesCreatedAndRegisteredPaths(): void
    {
        $created = $this->createTemporaryDirectory('created');
        new Filesystem()->dumpFile($created.'/nested/file.txt', 'contents');
        $registered = \dirname(__DIR__, 3).'/var/test/registered-'.bin2hex(random_bytes(6));
        $this->removeAfterTest($registered);
        new Filesystem()->mkdir($registered);
        self::assertDirectoryExists($created);
        self::assertStringStartsWith(\dirname(__DIR__, 3).'/var/test/', $created);

        $this->removeTemporaryDirectories();

        self::assertDirectoryDoesNotExist($created);
        self::assertDirectoryDoesNotExist($registered);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsOutsideVarTest(): iterable
    {
        yield 'absolute path elsewhere' => ['/tmp/elsewhere'];
        yield 'var/test itself' => [\dirname(__DIR__, 3).'/var/test'];
        yield 'dot segments that leave var/test' => [\dirname(__DIR__, 3).'/var/test/../cache'];
    }

    #[DataProvider('pathsOutsideVarTest')]
    #[TestDox('Refuses to remove var/test itself or a path outside it')]
    public function testRefusesPathsOutsideVarTest(string $path): void
    {
        $this->expectException(\LogicException::class);

        $this->removeAfterTest($path);
    }
}
