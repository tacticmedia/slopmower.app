<?php

declare(strict_types=1);

namespace App\Tests\Unit\Git;

use App\Git\GitConfigSecret;
use App\Git\GitRemote;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GitRemoteTest extends KernelTestCase
{
    #[TestDox('Puts the configuration before the subcommand, the URL after --end-of-options, and the secrets into the command')]
    public function testBuildsACommand(): void
    {
        $secret = new GitConfigSecret('http.https://github.com/.extraheader', 'AUTHORIZATION: basic eC1hY2Nlc3MtdG9rZW4=');
        $remote = new GitRemote('https://github.com/owner/name.git', ['safe.directory=/repositories/name'], [$secret]);

        $command = $remote->command('ls-remote', ['--symref'], ['HEAD'], '/srv/clone.git', 5.0);

        self::assertSame(
            ['-c', 'safe.directory=/repositories/name', 'ls-remote', '--symref', '--end-of-options', 'https://github.com/owner/name.git', 'HEAD'],
            $command->arguments,
        );
        self::assertSame('/srv/clone.git', $command->workingDirectory);
        self::assertSame(5.0, $command->timeoutSeconds);
        self::assertSame([$secret], $command->secrets);
    }

    #[TestDox('Accepts a file URL with a space in the path')]
    public function testAcceptsAFileUrlWithASpace(): void
    {
        self::assertSame('file:///repositories/my name', new GitRemote('file:///repositories/my name')->url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'an option' => ['--upload-pack=touch /tmp/x'];
        yield 'the ext transport' => ['ext::sh -c touch% /tmp/x'];
        yield 'HTTPS with user information' => ['https://x-access-token:token@github.com/owner/name.git'];
        yield 'a file URL with a host' => ['file://repositories/name'];
        yield 'a line break' => ["file:///repositories/name\n--upload-pack=x"];
    }

    #[DataProvider('invalidUrls')]
    #[TestDox('Refuses a URL that is not file:/// or https:// without user information')]
    public function testRefusesAnInvalidUrl(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GitRemote($url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidConfigurationEntries(): iterable
    {
        yield 'no value' => ['safe.directory'];
        yield 'an option' => ['--upload-pack=x'];
    }

    #[DataProvider('invalidConfigurationEntries')]
    #[TestDox('Refuses a configuration entry that is not <key>=<value>')]
    public function testRefusesAnInvalidConfigurationEntry(string $entry): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GitRemote('file:///repositories/name', [$entry]);
    }
}
