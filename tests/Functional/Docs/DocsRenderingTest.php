<?php

declare(strict_types=1);

namespace App\Tests\Functional\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DocsRenderingTest extends WebTestCase
{
    #[TestDox('Every page in docs/user renders without sign-in')]
    #[DataProvider('pages')]
    public function testEveryPageRendersWithoutSignIn(string $path, string $markdownPath): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSame(1, preg_match('/^# (.+)$/m', (string) file_get_contents($markdownPath), $heading));
        self::assertSame($heading[1], $crawler->filter('main h1')->text());
    }

    #[TestDox('Traversal attempts such as /docs/..%2Fcomposer.json and %2e%2e give 404')]
    #[DataProvider('traversalPaths')]
    public function testTraversalGivesNotFound(string $path): void
    {
        $client = static::createClient();

        $client->request('GET', $path);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Links to other pages render as /docs/<slug>')]
    public function testLinksToOtherPagesRenderAsDocsSlug(): void
    {
        $client = static::createClient();
        $hrefs = [];

        foreach (self::pages() as [$path]) {
            $hrefs = [...$hrefs, ...$client->request('GET', $path)->filter('main a[href]')->extract(['href'])];
        }

        self::assertContains('/docs/connect-your-agent', $hrefs);
        self::assertSame([], array_values(array_filter($hrefs, static fn (string $href): bool => str_contains($href, '.md'))));
    }

    #[TestDox('Headings get GitHub-style IDs and keep their text')]
    public function testHeadingsGetGitHubStyleIds(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/docs/reviewing');

        self::assertSame('The Slopmower check', $crawler->filter('h3#the-slopmower-check')->text());
        self::assertSame('Ask for a plan first', $crawler->filter('h2#ask-for-a-plan-first')->text());
    }

    #[TestDox('Every link with a fragment to a page of this host points to an ID of its target page')]
    public function testEveryFragmentLinkPointsToAnIdOfItsTargetPage(): void
    {
        $client = static::createClient();
        $links = [];

        foreach (self::pages() as [$path]) {
            foreach ($client->request('GET', $path)->filter('a[href*="#"]')->extract(['href']) as $href) {
                if (null !== parse_url($href, \PHP_URL_HOST)) {
                    continue;
                }

                [$targetPath, $fragment] = explode('#', $href, 2);
                $links[] = ['' === $targetPath ? $path : $targetPath, $fragment, "{$href} on {$path}"];
            }
        }

        self::assertNotSame([], $links);
        $idsByPath = [];

        foreach ($links as [$targetPath, $fragment, $link]) {
            $idsByPath[$targetPath] ??= $client->request('GET', $targetPath)->filter('[id]')->extract(['id']);
            self::assertContains($fragment, $idsByPath[$targetPath], $link);
        }
    }

    #[TestDox('A docs page links the privacy policy, the terms of service, and the support page')]
    public function testPageLinksTheLegalAndSupportPages(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/docs/connect-your-agent');

        self::assertSame(['/docs/privacy', '/docs/terms', '/docs/support'], $crawler->filter('footer nav[aria-label="Legal and support"] a')->extract(['href']));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pages(): iterable
    {
        foreach (glob(dirname(__DIR__, 3).'/docs/user/*.md') ?: [] as $markdownPath) {
            $slug = basename($markdownPath, '.md');
            yield $slug => ['index' === $slug ? '/docs' : '/docs/'.$slug, $markdownPath];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function traversalPaths(): iterable
    {
        foreach (['/docs/..%2Fcomposer.json', '/docs/%2e%2e', '/docs/skill'] as $path) {
            yield $path => [$path];
        }
    }
}
