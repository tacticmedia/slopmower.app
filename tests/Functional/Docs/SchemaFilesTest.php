<?php

declare(strict_types=1);

namespace App\Tests\Functional\Docs;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SchemaFilesTest extends WebTestCase
{
    #[TestDox('A schema file returns its bytes as application/schema+json')]
    public function testSchemaFileReturnsItsBytes(): void
    {
        $client = static::createClient();

        $client->request('GET', '/docs/schemas/v1/review.schema.json');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/schema+json');
        self::assertSame(file_get_contents(dirname(__DIR__, 3).'/docs/specs/schemas/review.schema.json'), $client->getInternalResponse()->getContent());
    }

    #[TestDox('An unknown file gives 404')]
    public function testUnknownFileGivesNotFound(): void
    {
        $client = static::createClient();

        $client->request('GET', '/docs/schemas/v1/unknown.schema.json');

        self::assertResponseStatusCodeSame(404);
    }
}
