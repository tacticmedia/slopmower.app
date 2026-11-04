<?php

declare(strict_types=1);

namespace App\GitHub;

use App\Domain\Error\CommitNotFound;
use App\Domain\Error\RateLimited;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\RepositoryUnavailableReason;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitInfo;
use App\Domain\Git\CommitSha;
use App\Domain\Git\CommitShaPrefix;
use App\Domain\Git\RefName;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\RepositoryId;
use App\Domain\Repository\RepositoryName;
use App\Git\BlameCommit;
use App\Git\BlameRange;
use App\Git\Blob;
use App\Git\ChangedFile;
use App\Git\ChangeStatus;
use App\Git\CodeReader;
use App\Git\CommitDetails;
use App\Git\GitCodeReader;
use App\Git\GitFailed;
use App\Git\LogCommit;
use App\Git\ObjectType;
use App\Git\ScratchCopies;
use App\Git\StreamedBlob;
use App\Git\TreeEntry;
use App\Source\FetchCommand;
use Psr\Cache\CacheItemPoolInterface;

/**
 * D112: reads one GitHub repository per call through {@see GitHubRepositoryClient}. The cache holds tree listings and
 * commit metadata, never blob bodies. {@see close()} deletes the scratch copies.
 */
final class GitHubCodeReader implements CodeReader
{
    private const string GRAPHQL = 'https://api.github.com/graphql';

    private const string SHA_MEDIA_TYPE = 'application/vnd.github.sha';

    private const string RAW_MEDIA_TYPE = 'application/vnd.github.raw+json';

    /** `git hash-object` of no bytes, for the absent side of a change. */
    private const string EMPTY_BLOB_SHA = 'e69de29bb2d1d6434b8b29ae775ad8c2e48c5391';

    /** GitHub lists at most 3,000 files of a commit. */
    private const int MAX_COMMIT_FILE_PAGES = 10;

    private const int BYTES_PER_KIB = 1024;

    /** GraphQL gives no reset time for a blame refused for the rate limit. */
    private const int BLAME_RETRY_AFTER_SECONDS = 60;

    /** 0000-01-01T00:00:00Z and 9999-12-31T23:59:59Z, the range of {@see \App\Domain\Event\Codec\TimeCodec}. */
    private const int FIRST_EVENT_TIME_UNIX_SECONDS = -62167219200;
    private const int LAST_EVENT_TIME_UNIX_SECONDS = 253402300799;

    private const array STATUSES = [
        'added' => ChangeStatus::Added,
        'removed' => ChangeStatus::Deleted,
        'modified' => ChangeStatus::Modified,
        'renamed' => ChangeStatus::Renamed,
        'copied' => ChangeStatus::Copied,
        'changed' => ChangeStatus::TypeChanged,
    ];

    private const string BLAME_QUERY = <<<'GRAPHQL'
        query ($owner: String!, $name: String!, $oid: GitObjectID!, $path: String!) {
          repository(owner: $owner, name: $name) {
            object(oid: $oid) {
              ... on Commit {
                blame(path: $path) {
                  ranges { startingLine endingLine commit { oid message author { name date } } }
                }
              }
            }
          }
        }
        GRAPHQL;

    /** @var array<string, list<TreeEntry>|null> Listings by tree-ish and recursion, null for a missing directory */
    private array $trees = [];

    /** @var array<string, int> Sizes of the listed blobs by object ID */
    private array $sizes = [];

    /** @var array<string, array<string, string|null>> {@see pathAfter()} by commit range */
    private array $changedPaths = [];

    /** @var array<string, GitCodeReader> Scratch copies by commit */
    private array $copies = [];

    private ?GitCodeReader $diffRepository = null;

    /** @var array<string, true> The blobs that {@see unifiedZeroDiff()} wrote */
    private array $diffBlobs = [];

    /** @var array<string, string> Bytes of read blobs up to 1 MiB by blob SHA */
    private array $blobs = [];

    /**
     * @param \Closure(int): void $checkScratchSize Checks the GitHub `size` against `repository_size` (D32) before a
     *                                              scratch copy
     * @param string              $gitBaseUrl       `https://github.com`, or a directory URL in tests
     */
    public function __construct(
        private readonly RepositoryId $repositoryId,
        private readonly RepositoryName $name,
        private readonly GitHubRepositoryClient $api,
        private readonly CacheItemPoolInterface $cache,
        private readonly ScratchCopies $scratch,
        private readonly \Closure $checkScratchSize,
        private readonly string $gitBaseUrl,
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function resolveCommit(RefName $ref): CommitSha
    {
        return $this->resolve($ref->toString());
    }

    /**
     * GitHub answers an ambiguous prefix as a missing commit, so it gives CommitNotFound too.
     */
    public function resolvePrefix(CommitShaPrefix $prefix, string $field = 'commit'): CommitSha
    {
        $commit = $this->resolve($prefix->toString());

        // A ref with the same name as the prefix wins.
        return str_starts_with($commit->toString(), $prefix->toString()) ? $commit : throw new CommitNotFound($prefix->toString());
    }

    public function hasCommit(CommitSha $commit): bool
    {
        return null !== $this->commit($commit);
    }

    public function commitInfo(CommitSha $commit): CommitInfo
    {
        $data = $this->commit($commit) ?? throw new CommitNotFound($commit->toString());
        $committedAt = max(self::FIRST_EVENT_TIME_UNIX_SECONDS, min(self::LAST_EVENT_TIME_UNIX_SECONDS, $data['committedAt']));

        return new CommitInfo($commit, self::subject($data['message']), new \DateTimeImmutable("@{$committedAt}"));
    }

    public function commitDetails(CommitSha $commit): CommitDetails
    {
        $data = $this->commit($commit) ?? throw new CommitNotFound($commit->toString());

        return new CommitDetails(
            $commit,
            null === $data['parent'] ? null : CommitSha::fromString($data['parent']),
            self::subject($data['message']),
            $data['message'],
            $data['authorName'],
            $data['authorEmail'],
            new \DateTimeImmutable('@'.$data['committedAt']),
        );
    }

    public function log(CommitSha $from, int $limit): array
    {
        return array_map(static function (mixed $item): LogCommit {
            $commit = self::map($item, 'commit');
            $author = self::map($commit, 'author');

            return new LogCommit(
                CommitSha::fromString(self::string($item, 'sha')),
                self::subject(self::string($commit, 'message')),
                mb_scrub(self::string($author, 'name'), 'UTF-8'),
                self::time(self::string($author, 'date')),
                self::time(self::string(self::map($commit, 'committer'), 'date')),
            );
        }, array_values($this->api->json($this->api->get("commits?sha={$from}&per_page={$limit}"))));
    }

    /**
     * The files of `commits/{sha}`, and the old blob, the modes, and the sizes from the trees of both commits. The
     * counts are null (binary) when no line changed although the blobs differ: GitHub also leaves out `patch` for a
     * large text diff, and gives counts 0 for a pure rename or mode change.
     */
    public function changedFiles(?CommitSha $parent, CommitSha $commit): array
    {
        $files = [];
        $path = "commits/{$commit}";

        for ($page = 0; null !== $path && $page < self::MAX_COMMIT_FILE_PAGES; ++$page) {
            $response = $this->api->get($path);
            $body = $this->api->json($response);
            array_push($files, ...array_values(\is_array($body['files'] ?? null) ? $body['files'] : []));
            $path = $this->api->nextPage($response);
        }

        $changes = array_values(array_filter(array_map(self::change(...), $files)));
        $this->prefetchTrees([
            ...array_map(static fn (array $change): array => [$commit, self::parentOf($change['path'])], array_filter($changes, static fn (array $change): bool => ChangeStatus::Deleted !== $change['status'])),
            ...null === $parent ? [] : array_map(static fn (array $change): array => [$parent, self::parentOf($change['oldPath'] ?? $change['path'])], array_filter($changes, static fn (array $change): bool => ChangeStatus::Added !== $change['status'])),
        ], false);

        return array_map(function (array $change) use ($parent, $commit): ChangedFile {
            $status = $change['status'];
            $old = ChangeStatus::Added === $status || null === $parent ? null : $this->entryAt($parent, RepoPath::fromString($change['oldPath'] ?? $change['path']));
            $new = ChangeStatus::Deleted === $status ? null : $this->entryAt($commit, RepoPath::fromString($change['path']));
            $binary = 0 === $change['additions'] + $change['deletions'] && ($old->objectId ?? self::EMPTY_BLOB_SHA) !== ($new->objectId ?? self::EMPTY_BLOB_SHA);

            return new ChangedFile(
                $status,
                $change['path'],
                $change['oldPath'],
                $old?->mode,
                $new?->mode,
                $old?->objectId,
                $new?->objectId,
                $binary ? null : $change['additions'],
                $binary ? null : $change['deletions'],
            );
        }, $changes);
    }

    public function objectSizes(array $objectIds): array
    {
        return array_intersect_key($this->sizes, array_flip($objectIds));
    }

    public function lsTree(CommitSha $commit, ?RepoPath $directory = null): array
    {
        return $this->tree($commit, $directory?->toString(), false);
    }

    public function lsTreeRecursive(CommitSha $commit, ?RepoPath $directory = null): array
    {
        return $this->tree($commit, $directory?->toString(), true);
    }

    public function entryAt(CommitSha $commit, RepoPath $path): ?TreeEntry
    {
        try {
            $siblings = $this->tree($commit, self::parentOf($path->toString()), false);
        } catch (GitFailed) {
            return null;
        }

        return array_find($siblings, static fn (TreeEntry $entry): bool => $entry->path === $path->toString());
    }

    /**
     * Reads the bytes of the binary check, then streams the rest. The size comes from a listed tree entry, else from
     * `Content-Length`, which GitHub leaves out of an uncached answer, so the request asks for no compression.
     */
    public function openBlob(BlobSha $blob): ?StreamedBlob
    {
        $response = $this->api->find("git/blobs/{$blob}", ['Accept' => self::RAW_MEDIA_TYPE, 'Accept-Encoding' => 'identity']);

        if (null === $response) {
            return null;
        }

        $chunks = $this->api->chunks($response);
        $head = '';

        while (\strlen($head) < Blob::BINARY_CHECK_BYTES && $chunks->valid()) {
            $head .= $chunks->current();
            $chunks->next();
        }

        $contentLength = $response->getHeaders(false)['content-length'][0] ?? null;

        return new StreamedBlob(
            $this->sizes[$blob->toString()] ?? (null === $contentLength ? null : (int) $contentLength),
            new Blob($blob, $head)->isBinary(),
            static function () use ($head, $chunks): \Generator {
                yield $head;

                while ($chunks->valid()) {
                    yield $chunks->current();
                    $chunks->next();
                }
            },
            $response->cancel(...),
        );
    }

    /**
     * Keeps the bytes of a blob up to 1 MiB until {@see close()}, so one locate reads each blob once.
     */
    public function readBlob(BlobSha $blob): ?Blob
    {
        if (isset($this->blobs[$blob->toString()])) {
            return new Blob($blob, $this->blobs[$blob->toString()]);
        }

        $response = $this->api->find("git/blobs/{$blob}", ['Accept' => self::RAW_MEDIA_TYPE]);
        $bytes = null === $response ? null : $this->api->content($response);

        if (null !== $bytes && \strlen($bytes) <= TreeEntry::MAX_RENDERABLE_BYTES) {
            $this->blobs[$blob->toString()] = $bytes;
        }

        return null === $bytes ? null : new Blob($blob, $bytes);
    }

    /**
     * The `renamed` and `removed` files of `compare`, which lists at most 300 files. GitHub compares from the merge base
     * of the two commits, which is $from when $to follows it.
     */
    public function pathAfter(CommitSha $from, CommitSha $to, RepoPath $path): ?string
    {
        $changedPaths = $this->changedPaths["{$from}\0{$to}"] ??= $this->comparedPaths($from, $to);

        return \array_key_exists($path->toString(), $changedPaths) ? $changedPaths[$path->toString()] : $path->toString();
    }

    /**
     * Writes both blobs into an empty scratch repository and diffs them there.
     */
    public function unifiedZeroDiff(BlobSha $from, BlobSha $to): string
    {
        $repository = $this->diffRepository ??= $this->scratch->emptyRepository();

        foreach ([$from, $to] as $blob) {
            if (!isset($this->diffBlobs[$blob->toString()])) {
                $this->scratch->writeBlob($repository, $this->readBlob($blob)->bytes ?? throw new GitFailed("GitHub has no blob {$blob}."));
                $this->diffBlobs[$blob->toString()] = true;
            }
        }

        return $repository->unifiedZeroDiff($from, $to);
    }

    public function filesContaining(CommitSha $commit, string $fixedText): array
    {
        return $this->withLocalCopy($commit, static fn (GitCodeReader $copy): array => $copy->filesContaining($commit, $fixedText));
    }

    /**
     * GraphQL blame, which has no ignore list. Adjacent ranges of one commit merge. GraphQL answers 200 also with
     * errors.
     */
    public function blame(CommitSha $commit, RepoPath $path, array $ignoredCommits): array
    {
        [$owner, $name] = explode('/', $this->name->toString(), 2);
        $response = $this->api->send('POST', self::GRAPHQL, [], ['json' => [
            'query' => self::BLAME_QUERY,
            'variables' => ['owner' => $owner, 'name' => $name, 'oid' => $commit->toString(), 'path' => $path->toString()],
        ]]);
        $this->api->checked($response);
        $body = $this->api->json($response);
        $errors = \is_array($body['errors'] ?? null) ? $body['errors'] : [];
        $blame = self::map(self::map(self::map($body, 'data'), 'repository'), 'object')['blame'] ?? null;

        if (array_any($errors, static fn (mixed $error): bool => 'RATE_LIMITED' === self::string($error, 'type'))) {
            throw new RateLimited(self::BLAME_RETRY_AFTER_SECONDS, 0, 0);
        }

        if ([] !== $errors || !\is_array($blame)) {
            throw new RepositoryUnavailable($this->repositoryId, RepositoryUnavailableReason::FetchFailed, new GitHubRequestFailed('GitHub answered the blame with errors.'));
        }

        $ranges = self::map($blame, 'ranges');
        $merged = [];
        $commits = [];

        foreach ($ranges as $range) {
            $rangeCommit = self::map($range, 'commit');
            $sha = self::string($rangeCommit, 'oid');
            $startLine = self::int($range, 'startingLine');
            $endLine = self::int($range, 'endingLine');
            $last = array_key_last($merged);

            if (null !== $last && $merged[$last][2] === $sha && $merged[$last][1] + 1 === $startLine) {
                $merged[$last][1] = $endLine;
            } else {
                $merged[] = [$startLine, $endLine, $sha];
            }

            $author = self::map($rangeCommit, 'author');
            $commits[$sha] ??= new BlameCommit(
                CommitSha::fromString($sha),
                mb_scrub(self::string($author, 'name'), 'UTF-8'),
                self::time(self::string($author, 'date')),
                mb_scrub(explode("\n", self::string($rangeCommit, 'message'), 2)[0], 'UTF-8'),
            );
        }

        return [
            'ranges' => array_map(static fn (array $range): BlameRange => new BlameRange($range[0], $range[1], CommitSha::fromString($range[2])), $merged),
            'commits' => array_values($commits),
        ];
    }

    /**
     * A `git fetch --depth=1` of the commit into a scratch directory, kept for the other calls of this request or
     * message until {@see close()}.
     */
    public function withLocalCopy(CommitSha $commit, callable $callback): mixed
    {
        if (!isset($this->copies[$commit->toString()])) {
            $sizeBytes = $this->sizeBytes();

            if (null !== $sizeBytes) {
                ($this->checkScratchSize)($sizeBytes);
            }

            $command = FetchCommand::gitHub($this->gitBaseUrl, $this->name, $this->api->token());
            $this->copies[$commit->toString()] = $this->scratch->copy($this->repositoryId, $command->url, $command->secretConfiguration, $commit);
        }

        return $callback($this->copies[$commit->toString()]);
    }

    public function close(): void
    {
        foreach ([...array_values($this->copies), ...null === $this->diffRepository ? [] : [$this->diffRepository]] as $copy) {
            $copy->close();
            $this->scratch->remove($copy);
        }

        $this->copies = [];
        $this->diffRepository = null;
        $this->diffBlobs = [];
        $this->blobs = [];
    }

    /**
     * @throws CommitNotFound For a missing ref and an ambiguous prefix, which GitHub answers alike
     */
    private function resolve(string $ref): CommitSha
    {
        $response = $this->api->find('commits/'.self::encodePath($ref), ['Accept' => self::SHA_MEDIA_TYPE]) ?? throw new CommitNotFound($ref);

        return CommitSha::fromString(trim($this->api->content($response)));
    }

    /**
     * @return array{parent: string|null, message: string, authorName: string, authorEmail: string, committedAt: int}|null
     */
    private function commit(CommitSha $commit): ?array
    {
        $item = $this->cache->getItem('c.'.hash('xxh128', "{$this->repositoryId}|{$commit}"));

        if ($item->isHit()) {
            /* @var array{parent: string|null, message: string, authorName: string, authorEmail: string, committedAt: int} */
            return $item->get();
        }

        $response = $this->api->find("git/commits/{$commit}");

        if (null === $response) {
            return null;
        }

        $body = $this->api->json($response);
        $parents = \is_array($body['parents'] ?? null) ? array_values($body['parents']) : [];
        $author = self::map($body, 'author');
        $data = [
            'parent' => [] === $parents ? null : self::string($parents[0], 'sha'),
            'message' => mb_scrub(self::string($body, 'message'), 'UTF-8'),
            'authorName' => mb_scrub(self::string($author, 'name'), 'UTF-8'),
            'authorEmail' => mb_scrub(self::string($author, 'email'), 'UTF-8'),
            'committedAt' => self::time(self::string(self::map($body, 'committer'), 'date'))->getTimestamp(),
        ];
        $this->cache->save($item->set($data));

        return $data;
    }

    /**
     * @return list<TreeEntry>
     *
     * @throws GitFailed When the directory is missing or not a directory
     */
    private function tree(CommitSha $commit, ?string $directory, bool $recursive): array
    {
        $this->prefetchTrees([[$commit, $directory]], $recursive);

        return $this->trees[self::treeKey($commit, $directory, $recursive)] ?? throw new GitFailed("fatal: not a tree object {$commit}:{$directory}");
    }

    /**
     * Lists the directories at their commits: the cached ones from the cache, the others in concurrent requests.
     *
     * @param list<array{CommitSha, string|null}> $directories Null for the root
     */
    private function prefetchTrees(array $directories, bool $recursive): void
    {
        $responses = [];

        foreach ($directories as [$commit, $directory]) {
            $key = self::treeKey($commit, $directory, $recursive);

            if (\array_key_exists($key, $this->trees) || isset($responses[$key])) {
                continue;
            }

            $item = $this->cache->getItem('t.'.hash('xxh128', "{$this->repositoryId}|{$key}"));

            if ($item->isHit()) {
                /** @var list<array{string, string, string, string, int|null}> $rows */
                $rows = $item->get();
                $this->trees[$key] = $this->entries($rows);

                continue;
            }

            $treeish = null === $directory ? $commit->toString() : "{$commit}:{$directory}";
            $url = $this->api->url('git/trees/'.self::encodePath($treeish).($recursive ? '?recursive=1' : ''));
            $responses[$key] = [$commit, $directory, $item, $url, $this->api->request('GET', $url)];
        }

        foreach ($responses as $key => [$commit, $directory, $item, $url, $response]) {
            $response = $this->api->answered($response, 'GET', $url);
            $rows = \in_array($this->api->checked($response), [404, 409, 422], true)
                ? $this->walk($commit, $directory, $recursive)
                : $this->rows($this->api->json($response), null === $directory ? '' : "{$directory}/", $recursive);
            $this->trees[$key] = null === $rows ? null : $this->entries($rows);

            if (null !== $rows) {
                $this->cache->save($item->set($rows));
            }
        }
    }

    /**
     * The listing of a directory through the cached listing of its parent. GitHub answers 404 for a missing directory
     * and 422 for a file, and the walk tells them from a directory that the `{sha}:{path}` form misses.
     *
     * @return list<array{string, string, string, string, int|null}>|null Null when the directory is missing or not a directory
     */
    private function walk(CommitSha $commit, ?string $directory, bool $recursive): ?array
    {
        if (null === $directory) {
            return null;
        }

        try {
            $siblings = $this->tree($commit, self::parentOf($directory), false);
        } catch (GitFailed) {
            return null;
        }

        $child = array_find($siblings, static fn (TreeEntry $entry): bool => $entry->path === $directory && ObjectType::Tree === $entry->type);

        return null === $child ? null : $this->rows($this->api->json($this->api->get("git/trees/{$child->objectId}".($recursive ? '?recursive=1' : ''))), "{$directory}/", $recursive);
    }

    /**
     * Rows of a tree answer, without the trees of a recursive one. A truncated recursive answer is replaced by the
     * recursive listing of each child tree.
     *
     * @param array<mixed> $body
     *
     * @return list<array{string, string, string, string, int|null}> Path, mode, type, object ID, size in bytes
     */
    private function rows(array $body, string $prefix, bool $recursive): array
    {
        $rows = [];

        if ($recursive && true === ($body['truncated'] ?? false)) {
            foreach ($this->rows($this->api->json($this->api->get('git/trees/'.self::string($body, 'sha'))), '', false) as [$path, $mode, $type, $objectId, $sizeBytes]) {
                $rows = [...$rows, ...'tree' === $type
                    ? $this->rows($this->api->json($this->api->get("git/trees/{$objectId}?recursive=1")), "{$prefix}{$path}/", true)
                    : [["{$prefix}{$path}", $mode, $type, $objectId, $sizeBytes]]];
            }

            return $rows;
        }

        foreach (\is_array($body['tree'] ?? null) ? $body['tree'] : [] as $entry) {
            $type = self::string($entry, 'type');

            if (!$recursive || 'tree' !== $type) {
                $size = \is_array($entry) && \is_int($entry['size'] ?? null) ? $entry['size'] : null;
                $rows[] = [$prefix.self::string($entry, 'path'), self::string($entry, 'mode'), $type, self::string($entry, 'sha'), 'blob' === $type ? $size : null];
            }
        }

        return $rows;
    }

    /**
     * @param list<array{string, string, string, string, int|null}> $rows
     *
     * @return list<TreeEntry>
     */
    private function entries(array $rows): array
    {
        return array_map(function (array $row): TreeEntry {
            if (null !== $row[4]) {
                $this->sizes[$row[3]] = $row[4];
            }

            return new TreeEntry($row[0], $row[1], ObjectType::from($row[2]), $row[3], $row[4]);
        }, $rows);
    }

    /**
     * @return array<string, string|null> The path at $to by the changed path at $from, null when it is deleted
     */
    private function comparedPaths(CommitSha $from, CommitSha $to): array
    {
        $changedPaths = [];
        $body = $this->api->json($this->api->get("compare/{$from}...{$to}"));

        foreach (\is_array($body['files'] ?? null) ? $body['files'] : [] as $file) {
            $change = self::change($file);

            match ($change['status'] ?? null) {
                ChangeStatus::Renamed => $changedPaths[(string) $change['oldPath']] = $change['path'],
                ChangeStatus::Deleted => $changedPaths[$change['path']] = null,
                default => null,
            };
        }

        return $changedPaths;
    }

    /**
     * The GitHub `size` of the repository, for the size limit of a scratch copy.
     *
     * @return int|null Null when the answer has no size
     */
    private function sizeBytes(): ?int
    {
        $sizeKib = $this->api->json($this->api->get(InstallationTokenProvider::API."repos/{$this->name}"))['size'] ?? null;

        return \is_int($sizeKib) ? $sizeKib * self::BYTES_PER_KIB : null;
    }

    /**
     * @return array{status: ChangeStatus, path: string, oldPath: string|null, additions: int, deletions: int}|null Null for `unchanged`
     */
    private static function change(mixed $file): ?array
    {
        $status = self::STATUSES[self::string($file, 'status')] ?? null;

        return null === $status ? null : [
            'status' => $status,
            'path' => self::string($file, 'filename'),
            'oldPath' => $status->hasTwoPaths() ? self::string($file, 'previous_filename') : null,
            'additions' => self::int($file, 'additions'),
            'deletions' => self::int($file, 'deletions'),
        ];
    }

    /**
     * `%s` of git: the first paragraph on one line.
     */
    private static function subject(string $message): string
    {
        $paragraph = preg_split('/\n[ \t]*\n/', trim($message, "\n"), 2)[0] ?? '';

        return mb_scrub(implode(' ', array_map(trim(...), explode("\n", $paragraph))), 'UTF-8');
    }

    private static function treeKey(CommitSha $commit, ?string $directory, bool $recursive): string
    {
        return "{$commit}:{$directory}\0".($recursive ? 'r' : '');
    }

    private static function parentOf(string $path): ?string
    {
        $slash = strrpos($path, '/');

        return false === $slash ? null : substr($path, 0, $slash);
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    private static function time(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value);
    }

    /**
     * @return array<mixed>
     */
    private static function map(mixed $value, string $key): array
    {
        $item = \is_array($value) ? $value[$key] ?? null : null;

        return \is_array($item) ? $item : [];
    }

    private static function string(mixed $value, string $key): string
    {
        $item = \is_array($value) ? $value[$key] ?? null : null;

        return \is_string($item) ? $item : '';
    }

    private static function int(mixed $value, string $key): int
    {
        $item = \is_array($value) ? $value[$key] ?? null : null;

        return \is_int($item) ? $item : 0;
    }
}
