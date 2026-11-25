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
use App\Domain\Repository\CodeAccess;
use App\Domain\Repository\RepositorySource;
use App\Entity\Review;
use App\Git\BlameCommit;
use App\Git\CodeReader;
use App\Git\ObjectType;
use App\Git\TreeEntry;
use App\Http\Cursor;
use DiceBear\Avatar;
use DiceBear\Style;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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

    /** docs/specs/README.md section 6. Increase `v2` when the Blame representation changes. */
    private const string BLAME_ETAG_VERSION = 'v2';

    private const string IGNORE_REVS_FILE = '.git-blame-ignore-revs';

    private ?Style $identiconStyle = null;

    public function __construct(
        private readonly FileQuery $files,
        private readonly FetchRepository $fetchRepository,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        #[Autowire('%kernel.project_dir%/vendor/dicebear/styles/src/identicon.json')]
        private readonly string $identiconStylePath,
    ) {
    }

    /**
     * `git ls-tree` at the review commit, paginated in memory. Sorted by directories first, then by path in byte order.
     * A recursive listing has no directories.
     */
    #[Route('/tree', name: 'tree', methods: ['GET'])]
    public function listTree(
        #[MapEntity(id: 'reviewId')] Review $review,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        TreeListingQuery $query = new TreeListingQuery(),
    ): JsonResponse {
        $dir = '' === $query->dir ? null : RepoPath::fromString($query->dir, 'dir');
        $filters = ['dir' => $dir?->toString() ?? '', 'recursive' => $query->recursive];
        $after = null === $query->cursor ? null : Cursor::decode($query->cursor, $filters, 'int', 'string');
        $reader = $this->fetchRepository->readyReader($review->getRepository()->getId());
        $commit = $review->getCommitSha();

        if (null !== $dir) {
            $entry = $reader->entryAt($commit, $dir) ?? throw new NotFound('No such directory at the review commit.');

            if (ObjectType::Tree !== $entry->type) {
                throw InvalidInput::field('dir', 'Must name a directory at the review commit.');
            }
        }

        // The other endpoints take only paths that RepoPath accepts, and git accepts more.
        $entries = array_filter(
            $query->recursive ? $reader->lsTreeRecursive($commit, $dir) : $reader->lsTree($commit, $dir),
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
        $reader = $this->fetchRepository->readyReader($review->getRepository()->getId());
        $entry = $this->files->entry($reader, $review, $path);
        // The body of Browser only differs.
        $browser = CodeAccess::Browser === $review->getRepository()->getCodeAccess() ? '-browser' : '';
        $headers = ['ETag' => 'W/"'.self::FILE_ETAG_VERSION."{$browser}-{$entry->objectId}\""] + self::FILE_CACHE_HEADERS;
        $notModified = new Response(null, Response::HTTP_OK, $headers);

        return $notModified->isNotModified($request) ? $notModified : $this->json($this->files->get($reader, $review, $entry), Response::HTTP_OK, $headers);
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
        $reader = $this->fetchRepository->readyReader($review->getRepository()->getId());

        return $blobs->response($reader, BlobSha::fromString($this->files->entry($reader, $review, $path)->objectId), $request);
    }

    #[Route('/blame', name: 'blame', methods: ['GET'])]
    public function getBlame(
        #[MapEntity(id: 'reviewId')] Review $review,
        Request $request,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        FilePathQuery $query,
    ): Response {
        $path = RepoPath::fromString((string) $query->path);
        $repository = $review->getRepository();
        $reader = $this->fetchRepository->readyReader($repository->getId());
        $commit = $review->getCommitSha();
        $entry = $this->files->entry($reader, $review, $path);
        $headers = ['ETag' => 'W/"'.self::BLAME_ETAG_VERSION."-{$repository->getCodeAccess()->value}-{$commit}-{$entry->objectId}\""] + self::FILE_CACHE_HEADERS;
        $notModified = new Response(null, Response::HTTP_OK, $headers);

        if ($notModified->isNotModified($request)) {
            return $notModified;
        }

        // Browser only cannot tell a binary file.
        if (!($this->files->get($reader, $review, $entry)->isRenderable() ?? $entry->isRenderableFile())) {
            throw InvalidInput::field('path', 'Must name a renderable file: not a symlink, not binary, and at most 1 MiB.');
        }

        // GitHub blame has no ignore list.
        $ignoredCommits = CodeAccess::Clone === $repository->getCodeAccess() ? self::ignoredCommits($reader, $commit) : [];
        ['ranges' => $ranges, 'commits' => $commits] = $reader->blame($commit, $path, $ignoredCommits);
        $commitUrl = RepositorySource::Github === $repository->getSource() ? "https://github.com/{$repository->getName()}/commit/" : null;
        [$commits, $contributors] = $this->withContributors($commits, $commitUrl);

        return $this->json(new Blame($review->getId(), $commit, $path, BlobSha::fromString($entry->objectId), [] !== $ignoredCommits, $ranges, $commits, $contributors), Response::HTTP_OK, $headers);
    }

    /**
     * D127: one contributor per email in lower case, or per name of a commit without an email.
     *
     * @param list<BlameCommit> $commits
     *
     * @return array{list<array{sha: CommitSha, authorName: string, authoredAt: \DateTimeImmutable, summary: string, contributorIndex: int, htmlUrl: string|null}>, list<array{name: string, avatarUrl: string}>}
     */
    private function withContributors(array $commits, ?string $commitUrl): array
    {
        $indexes = [];
        $contributors = [];
        $items = [];

        foreach ($commits as $commit) {
            // The NUL byte keeps a name apart from every email.
            $authorKey = '' === $commit->authorEmail ? "\0{$commit->authorName}" : mb_strtolower($commit->authorEmail);

            if (!isset($indexes[$authorKey])) {
                $indexes[$authorKey] = \count($contributors);
                $contributors[] = ['name' => $commit->authorName, 'avatarUrl' => $commit->gitHubAvatarUrl ?? $this->identicon($authorKey)];
            }

            $items[] = [
                'sha' => $commit->sha,
                'authorName' => $commit->authorName,
                'authoredAt' => $commit->authoredAt,
                'summary' => $commit->summary,
                'contributorIndex' => $indexes[$authorKey],
                'htmlUrl' => null === $commitUrl ? null : $commitUrl.$commit->sha,
            ];
        }

        return [$items, $contributors];
    }

    /**
     * The seed is an HMAC, because the SVG holds a hash of its seed, which would confirm a guessed email.
     */
    private function identicon(string $authorKey): string
    {
        if (null === $this->identiconStyle) {
            $definition = json_decode((string) file_get_contents($this->identiconStylePath), true, flags: \JSON_THROW_ON_ERROR);
            \assert(\is_array($definition));
            // CC0 needs no attribution, and the licence metadata is about 1 KB of each avatar.
            unset($definition['meta']);
            $this->identiconStyle = new Style($definition);
        }

        return new Avatar($this->identiconStyle, ['seed' => hash_hmac('sha256', $authorKey, $this->secret)])->toDataUri();
    }

    /**
     * The full SHAs of `.git-blame-ignore-revs` at the commit, as GitHub applies them. git cuts a line at `#` and
     * refuses any other text.
     *
     * @return list<string>
     */
    private static function ignoredCommits(CodeReader $reader, CommitSha $commit): array
    {
        $entry = $reader->entryAt($commit, RepoPath::fromString(self::IGNORE_REVS_FILE));
        $blob = null === $entry || !$entry->isRenderableFile() ? null : $reader->readBlob(BlobSha::fromString($entry->objectId));
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
