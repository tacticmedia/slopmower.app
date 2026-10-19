<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Security\CurrentActor;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A4 of docs/specs/rest-api.md.
 */
final class PreferencesTest extends WebTestCase
{
    use ApiFixtures;
    use JsonApiAssertions;
    use ResetDatabase;

    private const string PATH = '/api/v1/me/preferences';

    #[TestDox('A4 replaces the stored list')]
    public function testReplacesTheList(): void
    {
        $client = static::createClient();
        self::signIn($client);
        self::update($client, ['completedTours' => ['code_browser']]);

        self::assertSame(['completedTours' => ['file']], self::update($client, ['completedTours' => ['file']]));
    }

    #[TestDox('A4 stores the names in the order of Tour')]
    public function testStoresTheNamesInTourOrder(): void
    {
        $client = static::createClient();
        self::signIn($client);

        self::assertSame(['completedTours' => ['code_browser', 'file']], self::update($client, ['completedTours' => ['file', 'code_browser']]));
    }

    /**
     * @param array<string, mixed> $input
     */
    #[TestDox('A4 with a null or absent list keeps the stored list')]
    #[DataProvider('keepingInputs')]
    public function testNullOrAbsentListKeepsIt(array $input): void
    {
        $client = static::createClient();
        self::signIn($client);
        self::update($client, ['completedTours' => ['file']]);

        self::assertSame(['completedTours' => ['file']], self::update($client, $input));
    }

    /**
     * @param list<string> $completedTours
     */
    #[TestDox('A4 gives invalid_input for an unknown name and for a name twice')]
    #[DataProvider('invalidLists')]
    public function testInvalidListGivesInvalidInput(array $completedTours): void
    {
        $client = static::createClient();
        self::signIn($client);

        self::assertApiError(self::apiRequest($client, 'PATCH', self::PATH, ['completedTours' => $completedTours]), 422, 'invalid_input');
    }

    #[TestDox('A second GET /api/v1/me in the same session returns the new list')]
    public function testSecondReadInTheSessionReturnsTheNewList(): void
    {
        $client = static::createClient();
        self::signIn($client);
        self::assertSame(['completedTours' => []], self::assertApiResponse(self::apiRequest($client, 'GET', '/api/v1/me'), 200, 'me.schema.json')['preferences']);
        self::update($client, ['completedTours' => ['code_browser']]);

        self::assertSame(['completedTours' => ['code_browser']], self::assertApiResponse(self::apiRequest($client, 'GET', '/api/v1/me'), 200, 'me.schema.json')['preferences']);
    }

    #[TestDox('A stored name that Tour does not know is left out')]
    public function testUnknownStoredNameIsLeftOut(): void
    {
        $client = static::createClient();
        $user = self::signIn($client);
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE app_user SET completed_tours = :tours WHERE tenant_id = :tenantId AND id = :id',
            ['tours' => '["board", "file"]', 'tenantId' => $user->getTenantId()->toRfc4122(), 'id' => $user->getId()->toRfc4122()],
        );

        self::assertSame(['completedTours' => ['file']], self::assertApiResponse(self::apiRequest($client, 'GET', '/api/v1/me'), 200, 'me.schema.json')['preferences']);
    }

    #[TestDox('A bearer caller gets 403 forbidden')]
    public function testBearerCallerIsForbidden(): void
    {
        $client = static::createClient();
        [, $secret] = self::storeToken(self::signIn($client));

        $response = self::bearerRequest($client, $secret, 'PATCH', self::PATH, ['completedTours' => ['file']]);

        self::assertSame(CurrentActor::SESSION_ONLY, self::assertApiError($response, 403, 'forbidden')['message']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function keepingInputs(): iterable
    {
        yield 'null' => [['completedTours' => null]];
        yield 'absent' => [[]];
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidLists(): iterable
    {
        yield 'unknown name' => [['code_browser', 'board']];
        yield 'name twice' => [['file', 'file']];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private static function update(KernelBrowser $client, array $input): array
    {
        return self::assertApiResponse(self::apiRequest($client, 'PATCH', self::PATH, $input), 200, 'me.schema.json#/$defs/Preferences');
    }
}
