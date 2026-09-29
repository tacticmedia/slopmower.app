<?php

declare(strict_types=1);

namespace App\Tests\Unit\Git;

use App\Git\GitCommand;
use App\Git\GitConfigSecret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GitCommandTest extends KernelTestCase
{
    private const string TOKEN = 'eC1hY2Nlc3MtdG9rZW46Z2hzX3NlY3JldA==';
    private const string SECRET = 'AUTHORIZATION: basic '.self::TOKEN;

    /**
     * @return iterable<string, array{string}>
     */
    public static function argumentsWithASecret(): iterable
    {
        yield 'the whole secret' => ['http.extraheader='.self::SECRET];
        yield 'the token in a URL' => ['https://x-access-token:'.self::TOKEN.'@github.com/owner/name.git'];
    }

    #[DataProvider('argumentsWithASecret')]
    #[TestDox('Refuses a secret or a part of it with 8 or more bytes in the arguments')]
    public function testRefusesASecretInTheArguments(string $argument): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GitCommand(['fetch', $argument], '/srv', secrets: [new GitConfigSecret('http.extraheader', self::SECRET)]);
    }

    /**
     * @return iterable<string, array{\Closure(): GitCommand}>
     */
    public static function invalidCommands(): iterable
    {
        yield 'no arguments' => [static fn (): GitCommand => new GitCommand([], '/srv')];
        yield 'NUL byte' => [static fn (): GitCommand => new GitCommand(["a\0b"], '/srv')];
        yield 'relative working directory' => [static fn (): GitCommand => new GitCommand(['status'], 'var/tenants')];
        yield 'timeout of zero' => [static fn (): GitCommand => new GitCommand(['status'], '/srv', 0.0)];
        yield 'empty secret' => [static fn (): GitCommand => new GitCommand(['status'], '/srv', secrets: [new GitConfigSecret('http.extraheader', '')])];
        yield 'secret key with a line break' => [static fn (): GitCommand => new GitCommand(['status'], '/srv', secrets: [new GitConfigSecret("a\nb", 'value')])];
    }

    /**
     * @param \Closure(): GitCommand $create
     */
    #[DataProvider('invalidCommands')]
    #[TestDox('Refuses no arguments, a NUL byte, a relative working directory, a timeout that is not positive, and invalid secrets')]
    public function testRefusesInvalidCommands(\Closure $create): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $create();
    }

    #[TestDox('Redacts each secret and each of its parts with 8 or more bytes')]
    public function testRedactsEachSecretAndItsLongParts(): void
    {
        $command = new GitCommand(['status'], '/srv', secrets: [
            new GitConfigSecret('http.extraheader', self::SECRET),
            new GitConfigSecret('user.password', 'second-secret'),
        ]);

        self::assertSame(
            "fatal: '[redacted]', token [redacted], [redacted], basic stays",
            $command->redact("fatal: '".self::SECRET."', token ".self::TOKEN.', second-secret, basic stays'),
        );
    }

    #[TestDox('JSON and print_r show a command with its secret keys but without the secret values')]
    public function testJsonAndPrintRHideTheSecretValues(): void
    {
        $command = new GitCommand(['fetch'], '/srv', secrets: [new GitConfigSecret('http.extraheader', self::SECRET)]);

        foreach ([json_encode($command, \JSON_THROW_ON_ERROR), print_r($command, true)] as $text) {
            self::assertStringContainsString('http.extraheader', $text);
            self::assertStringNotContainsString(self::TOKEN, $text);
        }
    }
}
