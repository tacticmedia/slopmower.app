<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Error;

use App\Domain\Actor\Actor;
use App\Domain\Error\CommitNotFound;
use App\Domain\Error\CommitsNotAvailable;
use App\Domain\Error\DomainError;
use App\Domain\Error\ErrorCode;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\InvalidTransition;
use App\Domain\Error\NotFound;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\RepositoryUnavailableReason;
use App\Domain\Error\ReviewClosed;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\UserId;
use App\Domain\Request\Action;
use App\Domain\Request\RequestStatus;
use App\Tests\Support\Specs\SpecsFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DomainErrorDetailsTest extends KernelTestCase
{
    use SpecsFiles;

    #[TestDox('Each error returns the details keys of its definition in error.schema.json')]
    #[DataProvider('errors')]
    public function testReturnsTheDocumentedDetailsKeys(DomainError $error): void
    {
        $details = $error->details();
        $keys = null === $details ? null : array_keys($details);

        if (null !== $keys) {
            sort($keys);
        }

        self::assertSame(self::documentedDetailsKeys($error->code()), $keys);
    }

    /**
     * @return iterable<string, array{DomainError}>
     */
    public static function errors(): iterable
    {
        yield 'not_found' => [new NotFound()];
        yield 'invalid_input' => [InvalidInput::field('anchor.range.endLine', 'Must not be before startLine.')];
        yield 'invalid_transition' => [new InvalidTransition(
            RequestId::generate(),
            Action::Claim,
            RequestStatus::InProgress,
            Actor::agent(UserId::generate(), 'claude'),
            [Action::Reply],
        )];
        yield 'review_closed' => [new ReviewClosed(ReviewId::generate(), new \DateTimeImmutable('2026-09-29T07:31:12Z'))];
        yield 'commits_not_available' => [new CommitsNotAvailable([CommitSha::fromString('79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091')])];
        yield 'commit_not_found' => [new CommitNotFound('feature/x')];
        yield 'repository_unavailable' => [new RepositoryUnavailable(RepositoryId::generate(), RepositoryUnavailableReason::Cloning)];
    }

    /**
     * Follows `DetailsByCode` from the code to the `required` keys of its details definition.
     *
     * @return list<string>|null
     */
    private static function documentedDetailsKeys(ErrorCode $code): ?array
    {
        $schema = self::specsJson('schemas/error.schema.json');

        foreach ($schema['$defs']['DetailsByCode']['allOf'] as $rule) {
            $codeRule = $rule['if']['properties']['code'];

            if (!in_array($code->value, $codeRule['enum'] ?? [$codeRule['const']], true)) {
                continue;
            }

            $details = $rule['then']['properties']['details'];
            $references = array_column($details['anyOf'] ?? [$details], '$ref');

            if ([] === $references) {
                return null;
            }

            $keys = $schema['$defs'][substr($references[0], strlen('#/$defs/'))]['required'];
            sort($keys);

            return $keys;
        }

        self::fail("error.schema.json has no DetailsByCode rule for {$code->value}.");
    }
}
