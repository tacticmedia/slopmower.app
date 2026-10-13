<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Actor\ActorKind;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Anchor\SymbolKind;
use App\Domain\ApiToken\ApiTokenState;
use App\Domain\ApiToken\TokenScope;
use App\Domain\Error\RepositoryUnavailableReason;
use App\Domain\Event\EventType;
use App\Domain\Location\LocationStatus;
use App\Domain\Location\OrphanReason;
use App\Domain\Repository\RepositoryAccess;
use App\Domain\Repository\RepositorySource;
use App\Domain\Repository\SyncState;
use App\Domain\Request\Action;
use App\Domain\Request\Awaiting;
use App\Domain\Request\CommitLinkState;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Resolution;
use App\Domain\Review\IndexState;
use App\Domain\Review\ReviewState;
use App\Domain\Usage\UsageLimit;
use App\Entity\DraftKind;
use App\Entity\SignInMethod;
use App\Tests\Support\Specs\SpecsFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EnumContractTest extends KernelTestCase
{
    use SpecsFiles;

    /**
     * @param class-string<\BackedEnum> $enum
     * @param list<string>              $path keys from the schema root to the documented enum list
     */
    #[TestDox('Each enum equals its definition in docs/specs')]
    #[DataProvider('enums')]
    public function testEqualsTheDocumentedValues(string $enum, string $schema, array $path): void
    {
        $documented = self::specsJson("schemas/{$schema}");

        foreach ($path as $key) {
            $documented = $documented[$key];
        }

        self::assertSame($documented, array_map(static fn (\BackedEnum $case): int|string => $case->value, $enum::cases()));
    }

    /**
     * @return iterable<string, array{class-string<\BackedEnum>, string, list<string>}>
     */
    public static function enums(): iterable
    {
        $closedSets = [
            RepositorySource::class, RepositoryAccess::class, ReviewState::class, IndexState::class, RequestStatus::class,
            Awaiting::class, Resolution::class, AnchorKind::class, ActorKind::class, CommitLinkState::class,
            LocationStatus::class, OrphanReason::class,
        ];

        foreach ($closedSets as $enum) {
            $name = new \ReflectionClass($enum)->getShortName();

            yield $name => [$enum, 'common.schema.json', ['$defs', $name, 'enum']];
        }

        yield 'SymbolKind' => [SymbolKind::class, 'common.schema.json', ['$defs', 'SymbolKind', 'anyOf', '0', 'enum']];
        yield 'Action' => [Action::class, 'common.schema.json', ['$defs', 'Action', 'anyOf', '0', 'enum']];
        yield 'EventType' => [EventType::class, 'common.schema.json', ['$defs', 'EventType', 'anyOf', '0', 'enum']];
        yield 'UsageLimit' => [UsageLimit::class, 'common.schema.json', ['$defs', 'UsageLimit', 'anyOf', '0', 'enum']];
        yield 'SyncState' => [SyncState::class, 'repository.schema.json', ['$defs', 'Sync', 'properties', 'state', 'enum']];
        yield 'SignInMethod' => [SignInMethod::class, 'me.schema.json', ['properties', 'user', 'properties', 'signInMethod', 'enum']];
        yield 'RepositoryUnavailableReason' => [RepositoryUnavailableReason::class, 'error.schema.json', ['$defs', 'RepositoryUnavailableDetails', 'properties', 'reason', 'enum']];
        yield 'ApiTokenState' => [ApiTokenState::class, 'api-token.schema.json', ['properties', 'state', 'enum']];
        yield 'TokenScope' => [TokenScope::class, 'api-token.schema.json', ['properties', 'scope', 'enum']];
        yield 'DraftKind' => [DraftKind::class, 'draft.schema.json', ['properties', 'kind', 'enum']];
    }
}
