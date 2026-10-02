<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Request\Command\MarkReadyForReview;
use App\Domain\Request\Command\Open;
use App\Domain\Request\Command\Reopen;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\Command\RequestCommand;
use App\Tests\Support\Domain\InvalidInputAssertions;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * docs/specs/README.md section 2.2: a body or a required note has a non-whitespace character.
 */
final class RequiredTextTest extends KernelTestCase
{
    use InvalidInputAssertions;

    /**
     * @param \Closure(RequestScenario): RequestCommand $command
     */
    #[TestDox('A $field of whitespace only gives invalid_input')]
    #[DataProvider('commands')]
    public function testWhitespaceOnlyTextIsRejected(string $field, string $state, string $caller, \Closure $command): void
    {
        $scenario = RequestScenario::inState($state);

        self::assertInvalidInput([$field], static fn () => $scenario->decide($command($scenario), RequestScenario::caller($caller)));
    }

    /**
     * @return iterable<string, array{string, string, string, \Closure(RequestScenario): RequestCommand}>
     */
    public static function commands(): iterable
    {
        $blank = " \n\t ";

        yield 'open body' => ['body', 'open', 'user', static fn (RequestScenario $scenario): RequestCommand => new Open($scenario->anchor(), $blank)];
        yield 'reply body' => ['body', 'open', 'user', static fn (RequestScenario $scenario): RequestCommand => new Reply($scenario->requestId(), $blank)];
        yield 'ready note' => ['note', 'in_progress', 'claiming agent', static fn (RequestScenario $scenario): RequestCommand => new MarkReadyForReview($scenario->requestId(), $blank)];
        yield 'reopen note' => ['note', 'verified', 'user', static fn (RequestScenario $scenario): RequestCommand => new Reopen($scenario->requestId(), $blank)];
    }
}
