<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Domain\Identity\RepositoryId;
use App\Entity\User;
use App\Git\GitRunner;
use App\Git\TenantDataPaths;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TreeAndFileTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private KernelBrowser $client;

    private User $user;

    private GitRepositoryBuilder $source;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
    }

    #[TestDox('The pinned bytes stay the same after the source branch moves and a fetch runs')]
    public function testPinnedBytesSurviveBranchMoveAndFetch(): void
    {
        $this->source->commit(['src/App.php' => "<?php\n\necho 'v1';\n"]);
        $repositoryId = self::connectAndClone($this->client);
        $review = self::startReviewThroughApi($this->client, $repositoryId);
        $before = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$review['id']}/file?path=src/App.php"), 200, 'file-content.schema.json');
        // The rewrite leaves the review commit on no branch.
        $this->source->commit(['src/App.php' => "<?php\n\necho 'v2';\n"]);
        $this->source->git('reset', '--soft', 'HEAD~2');
        $movedHead = $this->source->commit(message: 'Rewritten');
        self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/repositories/{$repositoryId}/fetch"), 202, 'repository.schema.json');
        self::runQueuedMessages();
        $this->cloneGit($repositoryId, 'gc', '--quiet', '--prune=now');

        $repository = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/repositories/{$repositoryId}"), 200, 'repository.schema.json');
        $after = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$review['id']}/file?path=src/App.php"), 200, 'file-content.schema.json');
        self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$review['id']}/raw?path=src/App.php");

        self::assertIsArray($repository['sync']);
        self::assertSame($movedHead, $repository['sync']['defaultBranchHead']);
        self::assertSame($before, $after);
        self::assertSame("<?php\n\necho 'v1';\n", $this->client->getInternalResponse()->getContent());
    }

    #[TestDox('Binary and too-large files return metadata only')]
    public function testBinaryAndTooLargeFilesReturnMetadataOnly(): void
    {
        $this->source->commit(['logo.png' => "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR", 'big.txt' => str_repeat("a\n", 524_288).'a']);
        $review = self::startReviewThroughApi($this->client, self::connectAndClone($this->client));

        $binary = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$review['id']}/file?path=logo.png"), 200, 'file-content.schema.json');
        $tooLarge = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$review['id']}/file?path=big.txt"), 200, 'file-content.schema.json');

        $flags = ['sizeBytes' => true, 'binary' => true, 'tooLarge' => true, 'renderable' => true, 'lineCount' => true];
        self::assertSame(['sizeBytes' => 16, 'binary' => true, 'tooLarge' => false, 'renderable' => false, 'lineCount' => null], array_intersect_key($binary, $flags));
        self::assertSame(['sizeBytes' => 1_048_577, 'binary' => false, 'tooLarge' => true, 'renderable' => false, 'lineCount' => null], array_intersect_key($tooLarge, $flags));
    }

    #[TestDox('The file endpoint answers If-None-Match with 304')]
    public function testFileEndpointAnswersIfNoneMatch(): void
    {
        $this->source->commit(['src/Billing/InvoiceTotals.php' => "<?php\n\nfinal class InvoiceTotals\n{\n}\n"]);
        $review = self::startReviewThroughApi($this->client, self::connectAndClone($this->client));
        $uri = "/api/v1/reviews/{$review['id']}/file?path=src%2FBilling%2FInvoiceTotals.php";
        $blobSha = $this->source->revParse('main:src/Billing/InvoiceTotals.php');

        $first = self::apiRequest($this->client, 'GET', $uri);
        $file = self::assertApiResponse($first, 200, 'file-content.schema.json');
        $notModified = self::apiRequest($this->client, 'GET', $uri, server: ['HTTP_IF_NONE_MATCH' => "W/\"v1-{$blobSha}\""]);

        self::assertSame(['php', 5, null, "/api/v1/reviews/{$review['id']}/raw?path=src/Billing/InvoiceTotals.php"], [$file['language'], $file['lineCount'], $file['symlinkTarget'], $file['rawUrl']]);
        self::assertSame(304, $notModified->getStatusCode());
        self::assertSame('', (string) $notModified->getContent());
        foreach ([$first, $notModified] as $response) {
            self::assertSame("W/\"v1-{$blobSha}\"", $response->headers->get('ETag'));
            self::assertSame('no-cache, private', $response->headers->get('Cache-Control'));
            self::assertSame('Authorization, Cookie', $response->headers->get('Vary'));
        }
    }

    #[TestDox('Symlinks and submodules have their own types, and the file endpoint refuses a submodule')]
    public function testSymlinksAndSubmodules(): void
    {
        $this->source->commit(['src/App.php' => "<?php\n"]);
        symlink('src/App.php', "{$this->source->path}/link");
        $this->source->commit(message: 'Symlink');
        $this->source->git('update-index', '--add', '--cacheinfo', '160000,'.str_repeat('5', 40).',sub');
        $this->source->git('commit', '--quiet', '--message=Submodule');
        $review = self::startReviewThroughApi($this->client, self::connectAndClone($this->client));
        $code = "/api/v1/reviews/{$review['id']}";

        $root = self::assertApiResponse(self::apiRequest($this->client, 'GET', "{$code}/tree"), 200, 'lists.schema.json#/$defs/TreeListing');
        $link = self::assertApiResponse(self::apiRequest($this->client, 'GET', "{$code}/file?path=link"), 200, 'file-content.schema.json');
        $raw = self::apiRequest($this->client, 'GET', "{$code}/raw?path=link");
        $target = $this->client->getInternalResponse()->getContent();

        self::assertSame(['src' => 'directory', 'README.md' => 'file', 'link' => 'symlink', 'sub' => 'submodule'], array_column($root['items'], 'type', 'path'));
        self::assertSame(['symlink', '120000', 'src/App.php', false, null], [$link['type'], $link['mode'], $link['symlinkTarget'], $link['renderable'], $link['lineCount']]);
        self::assertSame(['src/App.php', 'text/plain; charset=utf-8'], [$target, $raw->headers->get('Content-Type')]);
        $error = self::assertApiError(self::apiRequest($this->client, 'GET', "{$code}/file?path=sub"), 422, 'invalid_input');
        self::assertSame(['path'], array_column($error['details']['violations'] ?? [], 'field'));
    }

    #[TestDox('The tree lists directories first, then byte order, and pages with a cursor')]
    public function testTreeOrderAndPaging(): void
    {
        // RepoPath refuses the leading "-" that git accepts, so the listing leaves -notes.md out.
        $this->source->commit(['b.txt' => "b\n", 'Z.txt' => "Z\n", '-notes.md' => "n\n", 'a/x.txt' => "x\n", 'c/y.txt' => "y\n", 'c/d/z.txt' => "z\n"]);
        $review = self::startReviewThroughApi($this->client, self::connectAndClone($this->client));
        $tree = "/api/v1/reviews/{$review['id']}/tree";

        $root = self::assertApiResponse(self::apiRequest($this->client, 'GET', $tree), 200, 'lists.schema.json#/$defs/TreeListing');
        $firstPage = self::assertApiResponse(self::apiRequest($this->client, 'GET', "{$tree}?dir=c&recursive=1&limit=1"), 200, 'lists.schema.json#/$defs/TreeListing');
        $secondPage = self::assertApiResponse(self::apiRequest($this->client, 'GET', "{$tree}?dir=c&recursive=1&limit=1&cursor={$firstPage['nextCursor']}"), 200, 'lists.schema.json#/$defs/TreeListing');

        self::assertSame(['a', 'c', 'README.md', 'Z.txt', 'b.txt'], array_column($root['items'], 'name'));
        self::assertSame(['directory', 'directory', 'file', 'file', 'file'], array_column($root['items'], 'type'));
        self::assertSame(['c/d/z.txt'], array_column($firstPage['items'], 'path'));
        self::assertSame(['c/y.txt'], array_column($secondPage['items'], 'path'));
        self::assertSame(['c', true, null], [$secondPage['dir'], $secondPage['recursive'], $secondPage['nextCursor']]);
        self::assertApiError(self::apiRequest($this->client, 'GET', "{$tree}?dir=b.txt"), 422, 'invalid_input');
        self::assertApiError(self::apiRequest($this->client, 'GET', "{$tree}?dir=missing"), 404, 'not_found');
    }

    private function cloneGit(string $repositoryId, string ...$arguments): void
    {
        $gitDir = self::getContainer()->get(TenantDataPaths::class)->clonePath($this->user->getTenantId(), RepositoryId::fromString($repositoryId));
        new GitRunner(new NullLogger())->run($gitDir, array_values($arguments));
    }
}
