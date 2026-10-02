<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Application\FetchRepository;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Git\RepoPath;
use App\Entity\Review;
use App\Git\GitReader;
use App\Git\ObjectType;
use App\Git\TreeEntry;
use App\Http\Cursor;

/**
 * V6: `git ls-tree` at the review commit, paginated in memory.
 */
final class TreeQuery
{
    public function __construct(
        private readonly FetchRepository $fetchRepository,
        private readonly GitReader $reader,
    ) {
    }

    /**
     * Sorted by directories first, then by path in byte order. A recursive listing has no directories.
     *
     * @param RepoPath|null $dir Null for the root
     *
     * @throws NotFound              No such directory at the review commit
     * @throws InvalidInput          $dir is not a directory, or the cursor is malformed or of other filters
     * @throws RepositoryUnavailable
     */
    public function list(Review $review, ?RepoPath $dir, bool $recursive, int $limit, ?string $cursor): TreeListing
    {
        $filters = ['dir' => $dir?->toString() ?? '', 'recursive' => $recursive];
        $after = null === $cursor ? null : self::sortKey(Cursor::decode($cursor, $filters));
        $gitDir = $this->fetchRepository->readyClonePath($review->getRepository()->getId());
        $commit = $review->getCommitSha();

        if (null !== $dir) {
            $entry = $this->reader->entryAt($gitDir, $commit, $dir) ?? throw new NotFound('No such directory at the review commit.');

            if (ObjectType::Tree !== $entry->type) {
                throw InvalidInput::field('dir', 'Must name a directory at the review commit.');
            }
        }

        // The other endpoints take only paths that RepoPath accepts, and git accepts more.
        $entries = array_filter(
            $recursive ? $this->reader->lsTreeRecursive($gitDir, $commit, $dir) : $this->reader->lsTree($gitDir, $commit, $dir),
            static fn (TreeEntry $entry): bool => self::isRepoPath($entry->path),
        );
        usort($entries, static fn (TreeEntry $a, TreeEntry $b): int => self::compare(self::keyOf($a), self::keyOf($b)));

        if (null !== $after) {
            $entries = array_values(array_filter($entries, static fn (TreeEntry $entry): bool => self::compare(self::keyOf($entry), $after) > 0));
        }

        $page = array_slice($entries, 0, $limit);
        $last = end($page);
        $nextCursor = count($entries) > $limit && false !== $last ? Cursor::encode(self::keyOf($last), $filters) : null;

        return new TreeListing($review->getId(), $commit, $dir?->toString() ?? '', $recursive, $page, $nextCursor);
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
     * @param array{int, string} $b
     */
    private static function compare(array $a, array $b): int
    {
        return $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]);
    }

    /**
     * @param list<mixed> $sortKey
     *
     * @return array{int, string}
     *
     * @throws InvalidInput
     */
    private static function sortKey(array $sortKey): array
    {
        if (2 !== count($sortKey) || !\is_int($sortKey[0]) || !\is_string($sortKey[1])) {
            throw Cursor::malformed();
        }

        return [$sortKey[0], $sortKey[1]];
    }
}
