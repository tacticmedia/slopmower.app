<?php

declare(strict_types=1);

namespace App\Tests\Unit\Source;

use App\Domain\Repository\RepositoryName;
use App\Source\GitHubFetchCommand;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GitHubFetchCommandTest extends KernelTestCase
{
    #[TestDox('The token is a basic extraheader for github.com, outside the URL')]
    public function testTokenIsAnExtraheaderForGitHub(): void
    {
        $command = GitHubFetchCommand::forRepository('https://github.com/', RepositoryName::github('acme/billing'), 'ghs_token');

        self::assertSame('https://github.com/acme/billing.git', $command->url);
        self::assertSame(['http.https://github.com/.extraheader' => 'AUTHORIZATION: basic eC1hY2Nlc3MtdG9rZW46Z2hzX3Rva2Vu'], $command->secretConfiguration);
        self::assertSame('x-access-token:ghs_token', base64_decode('eC1hY2Nlc3MtdG9rZW46Z2hzX3Rva2Vu', true));
    }
}
