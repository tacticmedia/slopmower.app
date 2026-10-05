<?php

declare(strict_types=1);

namespace App\Tests\Unit\Source;

use App\Domain\Repository\RepositoryName;
use App\Git\BareClone;
use App\Source\FetchCommand;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FetchCommandTest extends KernelTestCase
{
    #[TestDox('The token is a basic extraheader for github.com, outside the URL')]
    public function testTokenIsAnExtraheaderForGitHub(): void
    {
        $command = FetchCommand::gitHub('https://github.com/', RepositoryName::github('acme/billing'), 'ghs_token');

        self::assertSame('https://github.com/acme/billing.git', $command->url);
        self::assertSame(['http.https://github.com/.extraheader' => 'AUTHORIZATION: basic eC1hY2Nlc3MtdG9rZW46Z2hzX3Rva2Vu'], $command->secretConfiguration);
        self::assertSame('x-access-token:ghs_token', base64_decode('eC1hY2Nlc3MtdG9rZW46Z2hzX3Rva2Vu', true));
    }

    #[TestDox('The local arguments include safe.directory, both refspecs, --prune, and a file:// URL')]
    public function testLocalFetchArguments(): void
    {
        $command = FetchCommand::localDirectory("/repositories/it's 100%");

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
