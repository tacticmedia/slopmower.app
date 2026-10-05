<?php

declare(strict_types=1);

namespace App\Tests\Support\Anchoring;

use App\Anchoring\Locator;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SnapshotCapture;
use App\Domain\Anchor\SymbolRef;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\EventId;
use App\Domain\Location\Location;
use App\Entity\Repository;
use App\Entity\User;
use App\Git\Blob;
use App\Git\GitReader;
use App\Tenancy\TenantContext;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Git\GitRepositoryBuilder;

/**
 * A local source with its server clone, and anchors at its commits, for KernelTestCase tests that use
 * EnvironmentVariables, LocalSources, QueuedMessages, ResetDatabase, and TemporaryDirectories.
 */
trait LocatorFixtures
{
    private GitRepositoryBuilder $source;

    private Repository $repository;

    private User $user;

    /**
     * The lines `    $<variable> += N;` for N from $first to $last.
     */
    private static function codeLines(int $first, int $last, string $variable = 'total'): string
    {
        return implode('', array_map(static fn (int $n): string => "    \${$variable} += {$n};\n", range($first, $last)));
    }

    /**
     * @param array<string, string> $files Content by path, the first commit
     *
     * @return CommitSha The first commit, in the clone
     */
    private function cloneSource(array $files): CommitSha
    {
        $this->useTemporaryLocalSources();
        $this->source = GitRepositoryBuilder::init("{$this->localRepositoriesDirectory}/billing");
        $commit = $this->source->commit($files);
        $this->user = TenantFixtures::of(self::getContainer())->withLogin('jan')->create();
        self::getContainer()->get(TenantContext::class)->set($this->user->getTenantId());
        $this->repository = self::connect();
        self::runQueuedMessages();

        return CommitSha::fromString($commit);
    }

    /**
     * Moves files first, then writes the files with content and deletes the paths whose content is null.
     *
     * @param array<string, string|null> $files Content by path
     * @param array<string, string>      $moves New path by old path
     *
     * @return CommitSha The new commit, fetched into the clone
     */
    private function commitAndFetch(array $files, array $moves = []): CommitSha
    {
        foreach ($moves as $from => $to) {
            $this->source->git('mv', $from, $to);
        }

        foreach (array_keys(array_filter($files, static fn (?string $content): bool => null === $content)) as $deleted) {
            $this->source->git('rm', '--quiet', (string) $deleted);
        }

        $commit = $this->source->commit(array_filter($files, static fn (?string $content): bool => null !== $content));
        self::fetchRepository()->fetchNow($this->repository->getId());

        return CommitSha::fromString($commit);
    }

    private function fileAnchor(CommitSha $commit, string $path): Anchor
    {
        return Anchor::of(AnchorKind::File, RepoPath::fromString($path), $commit, $this->blob($commit, $path)->sha, null, null, null);
    }

    /**
     * A line anchor for one line, a range anchor for more, a symbol anchor with a symbol.
     */
    private function rangeAnchor(CommitSha $commit, string $path, int $startLine, int $endLine, ?SymbolRef $symbol = null): Anchor
    {
        $blob = $this->blob($commit, $path);
        $range = LineRange::of($startLine, $endLine);
        $kind = match (true) {
            null !== $symbol => AnchorKind::Symbol,
            $startLine === $endLine => AnchorKind::Line,
            default => AnchorKind::Range,
        };

        return Anchor::of($kind, RepoPath::fromString($path), $commit, $blob->sha, $range, $symbol, SnapshotCapture::capture($blob->bytes, $range));
    }

    private function locate(Anchor $anchor, CommitSha $target, ?EventId $openedEventId = null): Location
    {
        return self::getContainer()->get(Locator::class)->locate($this->repository, $openedEventId ?? EventId::generate(), $anchor, $target);
    }

    /**
     * @param array{int, int}|null $range
     *
     * @return array{string, string|null, array{int, int}|null, float, string|null}
     */
    private static function expected(string $status, ?string $path, ?array $range, float $confidence = 1.0, ?string $reason = null): array
    {
        return [$status, $path, $range, round($confidence, 4), $reason];
    }

    /**
     * @return array{string, string|null, array{int, int}|null, float, string|null}
     */
    private static function actual(Location $location): array
    {
        $range = $location->range;

        return [$location->status->value, $location->path?->toString(), null === $range ? null : [$range->startLine, $range->endLine], round($location->confidence, 4), $location->reason?->value];
    }

    private function blob(CommitSha $commit, string $path): Blob
    {
        $reader = self::getContainer()->get(GitReader::class);
        $clonePath = self::clonePath($this->repository);
        $entry = $reader->entryAt($clonePath, $commit, RepoPath::fromString($path));
        $blob = null === $entry ? null : $reader->readBlob($clonePath, BlobSha::fromString($entry->objectId));

        return $blob ?? self::fail("{$path} has no blob at {$commit}.");
    }
}
