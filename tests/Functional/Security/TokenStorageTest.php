<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TokenStorageTest extends WebTestCase
{
    use ApiFixtures;
    use JsonApiAssertions;
    use ResetDatabase;

    #[TestDox('No column holds the plaintext secret')]
    public function testNoColumnHoldsTheSecret(): void
    {
        $client = static::createClient();
        self::signIn($client);

        $secret = self::assertApiResponse(self::apiRequest($client, 'POST', '/api/v1/tokens', ['name' => 'codex desktop']), 201, 'api-token.schema.json#/$defs/ApiTokenWithSecret')['secret'];

        \assert(\is_string($secret));
        $rows = self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT * FROM api_token');
        self::assertCount(1, $rows);

        foreach ($rows[0] as $column => $value) {
            // The prefix column holds the first 9 characters by design.
            self::assertStringNotContainsString(substr($secret, 9), (string) $value, "Column {$column}");
        }
    }
}
