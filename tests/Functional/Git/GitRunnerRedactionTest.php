<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Git\GitCommand;
use App\Git\GitCommandFailed;
use App\Git\GitConfigSecret;
use App\Git\GitRemote;
use App\Git\GitRunner;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GitRunnerRedactionTest extends KernelTestCase
{
    use TemporaryDirectories;

    private const string SECRET = 'AUTHORIZATION: basic eC1hY2Nlc3MtdG9rZW46Z2hzX3NlY3JldA==';
    private const array SECRET_PARTS = [self::SECRET, 'AUTHORIZATION:', 'eC1hY2Nlc3MtdG9rZW46Z2hzX3NlY3JldA=='];
    private const string URL_TOKEN = 'ghs_urlTokenSecret';

    #[TestDox('A secret in the environment never appears in exception messages or logs')]
    public function testSecretNeverAppearsInExceptionMessagesOrLogs(): void
    {
        $runner = self::getContainer()->get(GitRunner::class);
        $clone = $this->createTemporaryDirectory('clone');
        $runner->mustRun(new GitCommand(['init', '--quiet', '--bare'], $clone));
        // Git prints an invalid numeric value in its error.
        $numericSecret = [new GitConfigSecret('core.abbrev', self::SECRET)];

        $failures = [
            'exit code' => self::failure(static fn () => $runner->mustRun(new GitCommand(['rev-parse', '--git-dir'], $clone, secrets: $numericSecret))),
            'timeout' => self::failure(static fn () => $runner->run(new GitCommand(
                ['-c', 'alias.leak=!git config --get http.extraheader >&2; sleep 2', 'leak'],
                $clone,
                0.5,
                [new GitConfigSecret('http.extraheader', self::SECRET)],
            ))),
            'long-lived process' => self::failure(static fn () => $runner->start(new GitCommand(['cat-file', '--batch'], $clone, secrets: $numericSecret))
                ->request("HEAD\n", static fn (string $output): ?int => null)),
        ];
        $texts = ['result error output' => $runner->run(new GitCommand(['rev-parse', '--git-dir'], $clone, secrets: $numericSecret))->errorOutput];
        foreach ($failures as $scenario => $failure) {
            self::assertNull($failure->getPrevious(), $scenario);
            $texts[$scenario.' message'] = $failure->getMessage();
            $texts[$scenario.' error output'] = $failure->errorOutput;
            $texts[$scenario.' trace'] = self::stringArguments($failure->getTrace());
        }
        foreach ($texts as $source => $text) {
            if (!str_ends_with($source, ' trace')) {
                self::assertStringContainsString(GitCommand::REDACTED, $text, $source);
            }
        }

        $logRecords = self::gitLogRecords();
        self::assertNotEmpty($logRecords);
        foreach ([...$texts, ...$logRecords] as $source => $text) {
            foreach (self::SECRET_PARTS as $secretPart) {
                self::assertStringNotContainsString($secretPart, $text, (string) $source);
            }
        }
    }

    #[TestDox('A refused secret argument or remote URL never appears in the exception trace')]
    public function testARefusedSecretNeverAppearsInTheExceptionTrace(): void
    {
        $clone = $this->createTemporaryDirectory('clone');

        $refusals = [
            'command' => self::refusal(static fn () => new GitCommand(
                ['-c', 'http.extraheader='.self::SECRET, 'fetch'],
                $clone,
                secrets: [new GitConfigSecret('http.extraheader', self::SECRET)],
            )),
            'remote' => self::refusal(static fn () => new GitRemote('https://x-access-token:'.self::URL_TOKEN.'@github.com/owner/name.git')),
        ];

        foreach ($refusals as $scenario => $refusal) {
            self::assertArrayHasKey('args', $refusal->getTrace()[0], $scenario);
            foreach ([...self::SECRET_PARTS, self::URL_TOKEN] as $secretPart) {
                self::assertStringNotContainsString($secretPart, self::stringArguments($refusal->getTrace()), $scenario);
            }
        }
    }

    /**
     * @param \Closure(): mixed $gitCall
     */
    private static function failure(\Closure $gitCall): GitCommandFailed
    {
        try {
            $gitCall();
        } catch (GitCommandFailed $failure) {
            return $failure;
        }

        self::fail('The git call did not fail.');
    }

    /**
     * @param \Closure(): mixed $construction
     */
    private static function refusal(\Closure $construction): \InvalidArgumentException
    {
        try {
            $construction();
        } catch (\InvalidArgumentException $refusal) {
            return $refusal;
        }

        self::fail('The construction was not refused.');
    }

    /**
     * Error pages and trace strings render the string arguments of each frame, but not the properties of objects.
     *
     * @param list<array<string, mixed>> $trace
     */
    private static function stringArguments(array $trace): string
    {
        $strings = [];
        $arguments = array_column($trace, 'args');
        array_walk_recursive($arguments, static function (mixed $argument) use (&$strings): void {
            if (\is_string($argument)) {
                $strings[] = $argument;
            }
        });

        return implode("\n", $strings);
    }

    /**
     * The test environment adds a TestHandler to the git channel.
     *
     * @return list<string> message and context of each record
     */
    private static function gitLogRecords(): array
    {
        $logger = self::getContainer()->get('monolog.logger.git');
        $testHandlers = array_values(array_filter($logger->getHandlers(), static fn (HandlerInterface $handler): bool => $handler instanceof TestHandler));
        self::assertCount(1, $testHandlers);

        return array_values(array_map(
            static fn (LogRecord $record): string => $record->message.' '.var_export($record->context, true),
            $testHandlers[0]->getRecords(),
        ));
    }
}
