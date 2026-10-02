<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Application\FetchRepository;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Git\BlobSha;
use App\Domain\Git\RepoPath;
use App\Entity\Review;
use App\Git\GitFailed;
use App\Git\GitReader;
use App\Git\ObjectType;
use App\Git\TreeEntry;

/**
 * V7 and the path lookup of V8 at the review commit.
 */
final class FileQuery
{
    public function __construct(private readonly GitReader $reader)
    {
    }

    /**
     * @param string $gitDir From {@see FetchRepository::readyClonePath()}
     *
     * @return TreeEntry A file or a symlink
     *
     * @throws NotFound     No such path at the review commit
     * @throws InvalidInput The path is a directory or a submodule
     * @throws GitFailed
     */
    public function entry(string $gitDir, Review $review, RepoPath $path): TreeEntry
    {
        $entry = $this->reader->entryAt($gitDir, $review->getCommitSha(), $path) ?? throw new NotFound('No such path at the review commit.');

        return ObjectType::Blob === $entry->type ? $entry : throw InvalidInput::field('path', 'Must name a file or a symlink, not a directory or a submodule.');
    }

    /**
     * Reads only the bytes of the binary check of a blob above {@see FileContent::MAX_RENDERABLE_BYTES}.
     *
     * @param TreeEntry $entry From {@see entry()}
     *
     * @throws GitFailed
     */
    public function get(string $gitDir, Review $review, TreeEntry $entry): FileContent
    {
        $blobSha = BlobSha::fromString($entry->objectId);
        $sizeBytes = $entry->sizeBytes ?? throw new \LogicException("The blob {$blobSha} has no size.");
        $blob = $sizeBytes > FileContent::MAX_RENDERABLE_BYTES ? null : $this->reader->readBlob($gitDir, $blobSha);

        return new FileContent(
            $review->getId(),
            $review->getCommitSha(),
            RepoPath::fromString($entry->path),
            $blobSha,
            $entry->mode,
            $entry->isSymlink(),
            $sizeBytes,
            $blob?->isBinary() ?? $this->isBinaryAtStart($gitDir, $blobSha),
            $blob,
        );
    }

    /**
     * @throws GitFailed
     */
    private function isBinaryAtStart(string $gitDir, BlobSha $blobSha): bool
    {
        $stream = $this->reader->openBlob($gitDir, $blobSha) ?? throw new \LogicException("The blob {$blobSha} is not in the clone.");
        $stream->close();

        return $stream->isBinary();
    }
}
