<?php

declare(strict_types=1);

namespace App\Tests\Support\Filesystem;

use PHPUnit\Framework\Attributes\After;
use Symfony\Component\Filesystem\Filesystem;

trait TemporaryDirectories
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    /**
     * A new empty directory under var/test/tmp.
     */
    private function temporaryDirectory(): string
    {
        $directory = dirname(__DIR__, 3).'/var/test/tmp/'.bin2hex(random_bytes(8));
        new Filesystem()->mkdir($directory);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    #[After]
    protected function removeTemporaryDirectories(): void
    {
        new Filesystem()->remove($this->temporaryDirectories);
        $this->temporaryDirectories = [];
    }
}
