<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Actor\ActorKind;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Anchor\SymbolKind;
use App\Domain\Error\ErrorCode;
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
use App\Domain\User\SignInMethod;
use App\Tests\Unit\Conventions\ProjectFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EnumContractTest extends KernelTestCase
{
    /**
     * An open set of docs/specs is compared with its known values.
     *
     * @return iterable<string, array{class-string<\BackedEnum>, string, string}>
     */
    public static function enumsAndTheirSets(): iterable
    {
        yield 'ActorKind' => [ActorKind::class, 'common.schema.json', '/$defs/ActorKind/enum'];
        yield 'AnchorKind' => [AnchorKind::class, 'common.schema.json', '/$defs/AnchorKind/enum'];
        yield 'SymbolKind' => [SymbolKind::class, 'common.schema.json', '/$defs/SymbolKind/anyOf/0/enum'];
        yield 'RepositoryUnavailableReason' => [RepositoryUnavailableReason::class, 'error.schema.json', '/$defs/RepositoryUnavailableDetails/properties/reason/enum'];
        yield 'EventType' => [EventType::class, 'common.schema.json', '/$defs/EventType/anyOf/0/enum'];
        yield 'LocationStatus' => [LocationStatus::class, 'common.schema.json', '/$defs/LocationStatus/enum'];
        yield 'OrphanReason' => [OrphanReason::class, 'common.schema.json', '/$defs/OrphanReason/enum'];
        yield 'RepositoryAccess' => [RepositoryAccess::class, 'common.schema.json', '/$defs/RepositoryAccess/enum'];
        yield 'RepositorySource' => [RepositorySource::class, 'common.schema.json', '/$defs/RepositorySource/enum'];
        yield 'SyncState' => [SyncState::class, 'repository.schema.json', '/$defs/Sync/properties/state/enum'];
        yield 'Action' => [Action::class, 'common.schema.json', '/$defs/Action/anyOf/0/enum'];
        yield 'Awaiting' => [Awaiting::class, 'common.schema.json', '/$defs/Awaiting/enum'];
        yield 'CommitLinkState' => [CommitLinkState::class, 'common.schema.json', '/$defs/CommitLinkState/enum'];
        yield 'RequestStatus' => [RequestStatus::class, 'common.schema.json', '/$defs/RequestStatus/enum'];
        yield 'Resolution' => [Resolution::class, 'common.schema.json', '/$defs/Resolution/enum'];
        yield 'IndexState' => [IndexState::class, 'common.schema.json', '/$defs/IndexState/enum'];
        yield 'ReviewState' => [ReviewState::class, 'common.schema.json', '/$defs/ReviewState/enum'];
        yield 'SignInMethod' => [SignInMethod::class, 'me.schema.json', '/properties/user/properties/signInMethod/enum'];
    }

    /**
     * @param class-string<\BackedEnum> $enum
     */
    #[DataProvider('enumsAndTheirSets')]
    #[TestDox('Each enum equals its set in docs/specs')]
    public function testEachEnumEqualsItsSetInDocsSpecs(string $enum, string $schemaFileName, string $pointer): void
    {
        $specValues = SpecFiles::schemaValue($schemaFileName, $pointer);
        self::assertIsArray($specValues);
        $enumValues = array_column($enum::cases(), 'value');
        sort($specValues);
        sort($enumValues);

        self::assertSame($specValues, $enumValues);
    }

    #[TestDox('Has a case for every enum in src/Domain except ErrorCode')]
    public function testHasACaseForEveryDomainEnum(): void
    {
        $coveredEnums = array_column(iterator_to_array(self::enumsAndTheirSets(), false), 0);
        sort($coveredEnums);

        self::assertSame(self::domainEnums(), $coveredEnums);
    }

    /**
     * @return list<class-string<\UnitEnum>>
     */
    private static function domainEnums(): array
    {
        $enums = [];
        foreach (array_keys(ProjectFiles::find(['src/Domain'], ['*.php'])) as $path) {
            $class = 'App\\'.str_replace('/', '\\', substr($path, \strlen('src/'), -\strlen('.php')));
            // {@see \App\Tests\Unit\Domain\Error\ErrorCodeTest} covers ErrorCode.
            if (enum_exists($class) && ErrorCode::class !== $class) {
                $enums[] = $class;
            }
        }
        sort($enums);

        return $enums;
    }
}
