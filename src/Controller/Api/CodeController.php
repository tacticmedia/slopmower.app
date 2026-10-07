<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\FetchRepository;
use App\Application\Query\Blame;
use App\Application\Query\BlobStream;
use App\Application\Query\FileQuery;
use App\Application\Query\TreeListing;
use App\Controller\Api\Input\FilePathQuery;
use App\Controller\Api\Input\TreeListingQuery;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Entity\Review;
use App\Git\GitReader;
use App\Git\ObjectType;
use App\Git\TreeEntry;
use App\Http\Cursor;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * V6 to V8 and V17 of docs/specs/rest-api.md section 5: reads at the review commit.
 */
#[Route('/api/v1/reviews/{reviewId}', name: 'api_review_')]
final class CodeController extends AbstractController
{
    /**
     * docs/specs/README.md section 6. Increase it when the FileContent representation changes, also for a new
     * `language` of a file name.
     */
    private const string FILE_ETAG_VERSION = 'v2';

    private const array FILE_CACHE_HEADERS = ['Cache-Control' => 'private, no-cache', 'Vary' => 'Authorization, Cookie'];

    /** docs/specs/README.md section 6. Increase `v1` when the Blame representation changes. */
    private const string BLAME_ETAG_VERSION = 'v1';

    private const string IGNORE_REVS_FILE = '.git-blame-ignore-revs';

    public function __construct(
        private readonly FileQuery $files,
        private readonly FetchRepository $fetchRepository,
    ) {
    }

    /**
     * `git ls-tree` at the review commit, paginated in memory. Sorted by directories first, then by path in byte order.
     * A recursive listing has no directories.
     */
    #[Route('/tree', name: 'tree', methods: ['GET'])]
    public function listTree(
        #[MapEntity(id: 'reviewId')] Review $review,
        GitReader $reader,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        TreeListingQuery $query = new TreeListingQuery(),
    ): JsonResponse {
        $dir = '' === $query->dir ? null : RepoPath::fromString($query->dir, 'dir');
        $filters = ['dir' => $dir?->toString() ?? '', 'recursive' => $query->recursive];
        $after = null === $query->cursor ? null : Cursor::decode($query->cursor, $filters, 'int', 'string');
        $gitDir = $this->fetchRepository->readyClonePath($review->getRepository()->getId());
        $commit = $review->getCommitSha();

        if (null !== $dir) {
            $entry = $reader->entryAt($gitDir, $commit, $dir) ?? throw new NotFound('No such directory at the review commit.');

            if (ObjectType::Tree !== $entry->type) {
                throw InvalidInput::field('dir', 'Must name a directory at the review commit.');
            }
        }

        // The other endpoints take only paths that RepoPath accepts, and git accepts more.
        $entries = array_filter(
            $query->recursive ? $reader->lsTreeRecursive($gitDir, $commit, $dir) : $reader->lsTree($gitDir, $commit, $dir),
            static fn (TreeEntry $entry): bool => self::isRepoPath($entry->path),
        );
        usort($entries, static fn (TreeEntry $a, TreeEntry $b): int => self::compare(self::keyOf($a), self::keyOf($b)));

        if (null !== $after) {
            $entries = array_values(array_filter($entries, static fn (TreeEntry $entry): bool => self::compare(self::keyOf($entry), $after) > 0));
        }

        $nextCursor = Cursor::next($entries, $query->limit, $filters, self::keyOf(...));

        return $this->json(new TreeListing($review->getId(), $commit, $dir?->toString() ?? '', $query->recursive, array_slice($entries, 0, $query->limit), $nextCursor));
    }

    #[Route('/file', name: 'file', methods: ['GET'])]
    public function getFile(
        #[MapEntity(id: 'reviewId')] Review $review,
        Request $request,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        FilePathQuery $query,
    ): Response {
        $path = RepoPath::fromString((string) $query->path);
        $gitDir = $this->fetchRepository->readyClonePath($review->getRepository()->getId());
        $entry = $this->files->entry($gitDir, $review, $path);
        $headers = ['ETag' => 'W/"'.self::FILE_ETAG_VERSION."-{$entry->objectId}\""] + self::FILE_CACHE_HEADERS;
        $notModified = new Response(null, Response::HTTP_OK, $headers);

        return $notModified->isNotModified($request) ? $notModified : $this->json($this->files->get($gitDir, $review, $entry), Response::HTTP_OK, $headers);
    }

    #[Route('/raw', name: 'raw', methods: ['GET'])]
    public function getRaw(
        #[MapEntity(id: 'reviewId')] Review $review,
        BlobStream $blobs,
        Request $request,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        FilePathQuery $query,
    ): Response {
        $path = RepoPath::fromString((string) $query->path);
        $gitDir = $this->fetchRepository->readyClonePath($review->getRepository()->getId());

        return $blobs->response($gitDir, BlobSha::fromString($this->files->entry($gitDir, $review, $path)->objectId), $request);
    }

    #[Route('/blame', name: 'blame', methods: ['GET'])]
    public function getBlame(
        #[MapEntity(id: 'reviewId')] Review $review,
        GitReader $reader,
        Request $request,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        FilePathQuery $query,
    ): Response {
        $path = RepoPath::fromString((string) $query->path);
        $gitDir = $this->fetchRepository->readyClonePath($review->getRepository()->getId());
        $commit = $review->getCommitSha();
        $entry = $this->files->entry($gitDir, $review, $path);
        $headers = ['ETag' => 'W/"'.self::BLAME_ETAG_VERSION."-{$commit}-{$entry->objectId}\""] + self::FILE_CACHE_HEADERS;
        $notModified = new Response(null, Response::HTTP_OK, $headers);

        if ($notModified->isNotModified($request)) {
            return $notModified;
        }

        if (!$this->files->get($gitDir, $review, $entry)->isRenderable()) {
            throw InvalidInput::field('path', 'Must name a renderable file: not a symlink, not binary, and at most 1 MiB.');
        }

        $ignoredCommits = $this->ignoredCommits($reader, $gitDir, $commit);
        ['ranges' => $ranges, 'commits' => $commits] = $reader->blame($gitDir, $commit, $path, $ignoredCommits);

        return $this->json(new Blame($review->getId(), $commit, $path, BlobSha::fromString($entry->objectId), [] !== $ignoredCommits, $ranges, $commits), Response::HTTP_OK, $headers);
    }

    /**
     * The full SHAs of `.git-blame-ignore-revs` at the commit, as GitHub applies them. git cuts a line at `#` and
     * refuses any other text.
     *
     * @return list<string>
     */
    private function ignoredCommits(GitReader $reader, string $gitDir, CommitSha $commit): array
    {
        $entry = $reader->entryAt($gitDir, $commit, RepoPath::fromString(self::IGNORE_REVS_FILE));
        $blob = null === $entry || !$entry->isRenderableFile() ? null : $reader->readBlob($gitDir, BlobSha::fromString($entry->objectId));
        $commits = [];

        foreach (explode("\n", $blob->bytes ?? '') as $line) {
            $sha = strtolower(trim(explode('#', $line, 2)[0]));

            if (1 === preg_match('/^([0-9a-f]{40}|[0-9a-f]{64})$/D', $sha)) {
                $commits[] = $sha;
            }
        }

        return $commits;
    }

    private static function isRepoPath(string $path): bool
    {
        try {
            RepoPath::fromString($path);

            return true;
        } catch (InvalidInput) {
            return false;
        }
    }

    /**
     * @return array{int, string}
     */
    private static function keyOf(TreeEntry $entry): array
    {
        return [ObjectType::Tree === $entry->type ? 0 : 1, $entry->path];
    }

    /**
     * @param array{int, string} $a
     * @param list<mixed>        $b A sort key that {@see Cursor::decode()} checked
     */
    private static function compare(array $a, array $b): int
    {
        return $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]);
    }
}
