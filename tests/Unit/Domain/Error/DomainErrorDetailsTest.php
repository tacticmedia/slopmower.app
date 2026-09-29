<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Error;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Error\CommitNotFound;
use App\Domain\Error\CommitsNotAvailable;
use App\Domain\Error\DomainError;
use App\Domain\Error\ErrorCode;
use App\Domain\Error\Forbidden;
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
use App\Tests\Unit\Conventions\ProjectFiles;
use App\Tests\Unit\Domain\SpecFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DomainErrorDetailsTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{ErrorCode, DomainError}>
     */
    public static function errors(): iterable
    {
        yield 'forbidden' => [ErrorCode::Forbidden, Forbidden::actorKind(ActorKind::Agent, 'open')];
        yield 'not_found' => [ErrorCode::NotFound, NotFound::resource('Request', RequestId::generate())];
        yield 'invalid_input' => [ErrorCode::InvalidInput, InvalidInput::field('reason', 'must have at least 20 characters')];
        yield 'invalid_transition' => [ErrorCode::InvalidTransition, new InvalidTransition(
            RequestId::generate(),
            Action::Claim,
            RequestStatus::InProgress,
            Actor::agent(UserId::generate(), 'codex desktop'),
            [Action::Reply],
        )];
        yield 'review_closed' => [ErrorCode::ReviewClosed, new ReviewClosed(ReviewId::generate(), new \DateTimeImmutable('2026-09-28T16:30:00Z'))];
        yield 'commits_not_available' => [ErrorCode::CommitsNotAvailable, new CommitsNotAvailable([CommitSha::fromString('e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e')])];
        yield 'commit_not_found' => [ErrorCode::CommitNotFound, new CommitNotFound('4f2a9c1')];
        yield 'repository_unavailable' => [ErrorCode::RepositoryUnavailable, new RepositoryUnavailable(RepositoryId::generate(), RepositoryUnavailableReason::Cloning)];
    }

    #[DataProvider('errors')]
    #[TestDox('Each error returns the details keys of its definition in error.schema.json')]
    public function testDetailsKeysMatchTheSchema(ErrorCode $code, DomainError $error): void
    {
        $details = $error->details();
        $detailsKeys = null === $details ? null : array_keys($details);
        if (null !== $detailsKeys) {
            sort($detailsKeys);
        }

        self::assertSame($code, $error->code());
        self::assertSame(self::schemaDetailsKeys($code), $detailsKeys);
    }

    #[TestDox('Has a case for every concrete DomainError in src/Domain/Error')]
    public function testCoversEveryConcreteDomainError(): void
    {
        $coveredClasses = [];
        foreach (self::errors() as [, $error]) {
            $coveredClasses[] = $error::class;
        }
        sort($coveredClasses);

        self::assertSame(self::concreteDomainErrorClasses(), $coveredClasses);
    }

    /**
     * @return list<string>|null sorted required keys, null when the schema requires null details
     */
    private static function schemaDetailsKeys(ErrorCode $code): ?array
    {
        $schema = SpecFiles::schema('error.schema.json');
        foreach ($schema['$defs']['DetailsByCode']['allOf'] as $rule) {
            $codeCondition = $rule['if']['properties']['code'];
            if (!\in_array($code->value, $codeCondition['enum'] ?? [$codeCondition['const']], true)) {
                continue;
            }

            $detailsDefinition = $rule['then']['properties']['details'];
            // invalid_input allows null or the object. InvalidInput always returns the object.
            foreach ($detailsDefinition['anyOf'] ?? [$detailsDefinition] as $variant) {
                if (isset($variant['$ref'])) {
                    self::assertStringStartsWith('#/$defs/', $variant['$ref']);
                    $keys = $schema['$defs'][substr($variant['$ref'], \strlen('#/$defs/'))]['required'];
                    sort($keys);

                    return $keys;
                }
            }
            self::assertSame(['type' => 'null'], $detailsDefinition);

            return null;
        }

        self::fail('error.schema.json has no details definition for '.$code->value);
    }

    /**
     * @return list<class-string<DomainError>>
     */
    private static function concreteDomainErrorClasses(): array
    {
        $classes = [];
        foreach (array_keys(ProjectFiles::find(['src/Domain/Error'], ['*.php'])) as $path) {
            $class = 'App\\'.str_replace('/', '\\', substr($path, \strlen('src/'), -\strlen('.php')));
            if (is_subclass_of($class, DomainError::class) && !new \ReflectionClass($class)->isAbstract()) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }
}
