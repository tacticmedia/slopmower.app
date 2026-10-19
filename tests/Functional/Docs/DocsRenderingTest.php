<?php

declare(strict_types=1);

namespace App\Tests\Functional\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DocsRenderingTest extends WebTestCase
{
    #[TestDox('Every page in docs/user renders without sign-in, with its H1 and "Slopmower Help" as the title')]
    #[DataProvider('pages')]
    public function testEveryPageRendersWithoutSignIn(string $path, string $markdownPath): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSame(1, preg_match('/^# (.+)$/m', (string) file_get_contents($markdownPath), $heading));
        self::assertSame($heading[1], $crawler->filter('main h1')->text());
        self::assertSame("{$heading[1]} - Slopmower Help", $crawler->filter('title')->text());
    }

    #[TestDox('The navigation links every page once and marks the current page')]
    public function testNavigationLinksEveryPageOnce(): void
    {
        $client = static::createClient();
        $paths = array_column([...self::pages()], 0);
        sort($paths);

        foreach ($paths as $path) {
            $navigation = $client->request('GET', $path)->filter('nav[aria-label="Help pages"]');
            $hrefs = $navigation->filter('a')->extract(['href']);
            sort($hrefs);

            self::assertSame($paths, $hrefs, $path);
            self::assertSame([$path], $navigation->filter('a[aria-current="page"]')->extract(['href']));
        }
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

        self::assertSame('The Slopmower check', $client->request('GET', '/docs/pull-requests')->filter('h2#the-slopmower-check')->text());
        self::assertSame('With the mouse', $client->request('GET', '/docs/comment')->filter('h2#with-the-mouse')->text());
    }

    #[TestDox('The line "<!-- On this page -->" renders a list of links to the H2 headings of its page')]
    public function testOnThisPageListsTheHeadingsOfItsPage(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/docs/requests');

        $headingIds = $crawler->filter('main h2[id]')->extract(['id']);
        self::assertNotSame([], $headingIds);
        self::assertSame(array_map(static fn (string $id): string => "#{$id}", $headingIds), $crawler->filter('main ul.table-of-contents a')->extract(['href']));
        self::assertStringNotContainsString('On this page --&gt;', (string) $client->getResponse()->getContent());
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
        self::assertLinksResolve($client, $links);
    }

    #[TestDox('Every URL of assets/layout/helpLinks.ts points to a page, and its fragment to an ID of that page')]
    public function testEveryHelpLinkOfTheAppPointsToAPageAndAHeading(): void
    {
        $client = static::createClient();
        preg_match_all("~'(/docs[^'#]*)(?:#([^']+))?'~", (string) file_get_contents(dirname(__DIR__, 3).'/assets/layout/helpLinks.ts'), $urls, \PREG_SET_ORDER);
        $links = [];

        foreach ($urls as $url) {
            $client->request('GET', $url[1]);
            self::assertResponseIsSuccessful($url[0]);

            if (isset($url[2])) {
                $links[] = [$url[1], $url[2], $url[0]];
            }
        }

        self::assertNotSame([], $links);
        self::assertLinksResolve($client, $links);
    }

    #[TestDox('A docs page links the privacy policy, the terms of service, and the support page')]
    public function testPageLinksTheLegalAndSupportPages(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/docs/connect-your-agent');

        self::assertSame(['/docs/privacy', '/docs/terms', '/docs/support'], $crawler->filter('footer nav[aria-label="Legal and support"] a')->extract(['href']));
    }

    /**
     * @param list<array{string, string, string}> $links target path, fragment, and description of each link
     */
    private static function assertLinksResolve(KernelBrowser $client, array $links): void
    {
        $idsByPath = [];

        foreach ($links as [$targetPath, $fragment, $link]) {
            $idsByPath[$targetPath] ??= $client->request('GET', $targetPath)->filter('[id]')->extract(['id']);
            self::assertContains($fragment, $idsByPath[$targetPath], $link);
        }
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
