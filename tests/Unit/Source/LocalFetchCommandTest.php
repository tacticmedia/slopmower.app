<?php

declare(strict_types=1);

namespace App\Tests\Unit\Source;

use App\Git\BareClone;
use App\Source\LocalFetchCommand;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LocalFetchCommandTest extends KernelTestCase
{
    #[TestDox('The arguments include safe.directory, both refspecs, --prune, and a file:// URL')]
    public function testFetchArguments(): void
    {
        $command = LocalFetchCommand::forDirectory("/repositories/it's 100%");

        self::assertSame(
            [
                'fetch',
                "--upload-pack=git -c safe.directory='/repositories/it'\\''s 100%' -c safe.directory='/repositories/it'\\''s 100%/.git' upload-pack",
                '--no-auto-maintenance',
                '--prune',
                '--end-of-options',
                'file:///repositories/it%27s%20100%25',
                '+refs/heads/*:refs/heads/*',
                '+refs/tags/*:refs/tags/*',
            ],
            BareClone::fetchArguments($command->url, $command->uploadPack),
        );
    }
}
