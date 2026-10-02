<?php

declare(strict_types=1);

namespace App\Tests\Functional\Docs;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RawSkillTest extends WebTestCase
{
    #[TestDox('The response bytes equal docs/user/skill/SKILL.md')]
    public function testResponseBytesEqualTheSkillFile(): void
    {
        $client = static::createClient();

        $client->request('GET', '/docs/skill/SKILL.md');

        self::assertResponseIsSuccessful();
        self::assertSame(self::skill(), $client->getResponse()->getContent());
        self::assertResponseHeaderSame('ETag', '"'.hash('sha256', self::skill()).'"');
        self::assertResponseHeaderSame('Cache-Control', 'max-age=300, public');
    }

    #[TestDox('The content type is text/markdown and the disposition is inline, or attachment with download=1')]
    public function testContentTypeAndDisposition(): void
    {
        $client = static::createClient();

        $client->request('GET', '/docs/skill/SKILL.md');

        self::assertResponseHeaderSame('Content-Type', 'text/markdown; charset=utf-8');
        self::assertResponseHeaderSame('Content-Disposition', 'inline; filename="SKILL.md"');

        $client->request('GET', '/docs/skill/SKILL.md?download=1');

        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="SKILL.md"');
    }

    #[TestDox('A matching If-None-Match gives 304')]
    public function testMatchingIfNoneMatchGivesNotModified(): void
    {
        $client = static::createClient();
        $etag = '"'.hash('sha256', self::skill()).'"';

        $client->request('GET', '/docs/skill/SKILL.md', server: ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertResponseStatusCodeSame(304);
        self::assertSame('', $client->getResponse()->getContent());
        self::assertResponseHeaderSame('ETag', $etag);
    }

    private static function skill(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/docs/user/skill/SKILL.md');
    }
}
