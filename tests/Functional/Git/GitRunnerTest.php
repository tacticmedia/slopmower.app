<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Git\GitCommand;
use App\Git\GitCommandFailed;
use App\Git\GitConfigSecret;
use App\Git\GitRunner;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class GitRunnerTest extends KernelTestCase
{
    use TemporaryDirectories;

    #[TestDox('Runs git with the fixed environment and, of the inherited environment, only PATH')]
    public function testRunsGitWithTheFixedEnvironment(): void
    {
        self::assertArrayHasKey('DATABASE_URL', getenv() + $_ENV);

        $output = self::runner()->mustRun(new GitCommand(['-c', 'alias.environment=!env', 'environment'], $this->createTemporaryDirectory('git')))->output;

        $variables = explode("\n", trim($output));
        foreach (['GIT_TERMINAL_PROMPT=0', 'GIT_CONFIG_NOSYSTEM=1', 'GIT_CONFIG_GLOBAL=/dev/null', 'LC_ALL=C', 'GIT_CONFIG_COUNT=0'] as $variable) {
            self::assertContains($variable, $variables);
        }
        $names = array_map(static fn (string $variable): string => explode('=', $variable, 2)[0], $variables);
        foreach (['DATABASE_URL', 'LOCK_DSN', 'APP_SECRET', 'HOME'] as $inheritedName) {
            self::assertNotContains($inheritedName, $names);
        }
    }

    #[TestDox('Passes each secret to git as a configuration value')]
    public function testPassesEachSecretAsAConfigurationValue(): void
    {
        $result = self::runner()->mustRun(new GitCommand(
            ['config', '--get-regexp', '^secret\.'],
            $this->createTemporaryDirectory('git'),
            secrets: [new GitConfigSecret('secret.first', 'first value'), new GitConfigSecret('secret.second', 'second value')],
        ));

        self::assertSame("secret.first first value\nsecret.second second value\n", $result->output);
    }

    #[TestDox('Passes secrets through the environment, never through the git arguments')]
    public function testPassesSecretsThroughTheEnvironmentNeverThroughTheArguments(): void
    {
        $secrets = [new GitConfigSecret('http.extraheader', 'AUTHORIZATION: basic Zmlyc3Qtc2VjcmV0'), new GitConfigSecret('secret.second', 'second-secret-value')];
        $workingDirectory = $this->createTemporaryDirectory('git');
        $aliasOutput = static fn (string $alias): string => self::runner()->mustRun(new GitCommand(
            ['-c', 'alias.argv=!tr "\000" " " < /proc/$PPID/cmdline', '-c', 'alias.environment=!env', $alias],
            $workingDirectory,
            secrets: $secrets,
        ))->output;

        $argv = $aliasOutput('argv');
        $variables = explode("\n", trim($aliasOutput('environment')));

        self::assertStringStartsWith('git -c ', $argv);
        self::assertStringEndsWith(' -c alias.environment=!env argv ', $argv);
        foreach (['AUTHORIZATION: basic Zmlyc3Qtc2VjcmV0', 'AUTHORIZATION:', 'Zmlyc3Qtc2VjcmV0', 'second-secret-value'] as $secretPart) {
            self::assertStringNotContainsString($secretPart, $argv);
        }
        foreach ([
            'GIT_CONFIG_COUNT=2',
            'GIT_CONFIG_KEY_0=http.extraheader',
            'GIT_CONFIG_VALUE_0=AUTHORIZATION: basic Zmlyc3Qtc2VjcmV0',
            'GIT_CONFIG_KEY_1=secret.second',
            'GIT_CONFIG_VALUE_1=second-secret-value',
        ] as $variable) {
            self::assertContains($variable, $variables);
        }
    }

    #[TestDox('Returns a failed command as a result, and mustRun throws')]
    public function testReturnsAFailedCommandAsAResultAndMustRunThrows(): void
    {
        $clone = $this->createTemporaryDirectory('clone');
        self::runner()->mustRun(new GitCommand(['init', '--quiet', '--bare'], $clone));
        $command = new GitCommand(['rev-parse', '--verify', '--quiet', '--end-of-options', 'refs/heads/missing'], $clone);

        $result = self::runner()->run($command);

        self::assertFalse($result->isSuccessful());
        self::assertSame(1, $result->exitCode);
        self::assertSame('', $result->output);
        try {
            self::runner()->mustRun($command);
            self::fail('mustRun did not throw.');
        } catch (GitCommandFailed $failure) {
            self::assertSame(1, $failure->exitCode);
            self::assertStringContainsString('git rev-parse --verify --quiet --end-of-options refs/heads/missing exited with code 1', $failure->getMessage());
        }
    }

    #[TestDox('Stops a command at its timeout')]
    public function testStopsACommandAtItsTimeout(): void
    {
        $this->expectException(GitCommandFailed::class);
        $this->expectExceptionMessage('exceeded the timeout of 0.3 seconds');

        self::runner()->run(new GitCommand(['-c', 'alias.hang=!sleep 5', 'hang'], $this->createTemporaryDirectory('git'), 0.3));
    }

    #[TestDox('Reports a missing working directory as a failure')]
    public function testReportsAMissingWorkingDirectoryAsAFailure(): void
    {
        $this->expectException(GitCommandFailed::class);
        $this->expectExceptionMessage('did not run');

        self::runner()->run(new GitCommand(['status'], $this->createTemporaryDirectory('git').'/missing'));
    }

    #[TestDox('Never looks for a repository above the working directory')]
    public function testNeverLooksForARepositoryAboveTheWorkingDirectory(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $subdirectory = $source->directory().'/subdirectory';
        new Filesystem()->mkdir($subdirectory);

        self::assertSame(0, self::runner()->run(new GitCommand(['rev-parse', '--git-dir'], $source->directory()))->exitCode);
        self::assertSame(128, self::runner()->run(new GitCommand(['rev-parse', '--git-dir'], $subdirectory))->exitCode);
    }

    #[TestDox('Answers several requests through one long-lived process')]
    public function testAnswersSeveralRequestsThroughOneLongLivedProcess(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $source->commit(['text.txt' => "alpha\nbeta\n", 'binary.bin' => "\0\x01\n\xff"]);

        $process = self::runner()->start(new GitCommand(['cat-file', '--batch'], $source->directory()));
        $text = $process->request("HEAD:text.txt\n", self::batchResponseLength(...));
        $missing = $process->request("HEAD:missing.txt\n", self::batchResponseLength(...));
        $binary = $process->request("HEAD:binary.bin\n", self::batchResponseLength(...));
        $process->close();

        self::assertSame($source->resolve('HEAD:text.txt')." blob 11\nalpha\nbeta\n\n", $text);
        self::assertSame("HEAD:missing.txt missing\n", $missing);
        self::assertSame($source->resolve('HEAD:binary.bin')." blob 4\n\0\x01\n\xff\n", $binary);
        self::assertFalse($process->isRunning());
    }

    #[TestDox('Fails a request of a long-lived process that exceeds the timeout, and stops git')]
    public function testFailsARequestThatExceedsTheTimeout(): void
    {
        $clone = $this->createTemporaryDirectory('clone');
        self::runner()->mustRun(new GitCommand(['init', '--quiet', '--bare'], $clone));
        $process = self::runner()->start(new GitCommand(['cat-file', '--batch'], $clone, 0.3));

        try {
            $process->request("HEAD\n", static fn (string $output): ?int => null);
            self::fail('The request did not time out.');
        } catch (GitCommandFailed $failure) {
            self::assertStringContainsString('git cat-file --batch exceeded the timeout of 0.3 seconds', $failure->getMessage());
        }
        self::assertFalse($process->isRunning());
    }

    #[TestDox('Fails a request of a long-lived process that exited with its exit code, before the timeout')]
    public function testFailsARequestOfAnExitedProcess(): void
    {
        $clone = $this->createTemporaryDirectory('clone');
        self::runner()->mustRun(new GitCommand(['init', '--quiet', '--bare'], $clone));
        $process = self::runner()->start(new GitCommand(['-c', 'core.abbrev=invalid', 'cat-file', '--batch'], $clone, 30.0));

        try {
            $process->request("HEAD\n", self::batchResponseLength(...));
            self::fail('The request did not fail.');
        } catch (GitCommandFailed $failure) {
            self::assertSame(128, $failure->exitCode);
            self::assertStringContainsString("bad numeric config value 'invalid' for 'core.abbrev'", $failure->errorOutput);
        }
    }

    /**
     * `<oid> <type> <size>\n<content>\n`, or `<object> missing\n`.
     */
    private static function batchResponseLength(string $output): ?int
    {
        $headerEnd = strpos($output, "\n");
        if (false === $headerEnd) {
            return null;
        }
        $header = explode(' ', substr($output, 0, $headerEnd));

        return 3 === \count($header) ? $headerEnd + 1 + (int) $header[2] + 1 : $headerEnd + 1;
    }

    private static function runner(): GitRunner
    {
        return self::getContainer()->get(GitRunner::class);
    }
}
