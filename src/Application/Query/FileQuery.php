<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Git\BlobSha;
use App\Domain\Git\RepoPath;
use App\Domain\Repository\CodeAccess;
use App\Entity\Review;
use App\Git\CodeReader;
use App\Git\GitFailed;
use App\Git\ObjectType;
use App\Git\TreeEntry;

/**
 * V7 and the path lookup of V8 at the review commit.
 */
final class FileQuery
{
    /**
     * @return TreeEntry A file or a symlink
     *
     * @throws NotFound     No such path at the review commit
     * @throws InvalidInput The path is a directory or a submodule
     * @throws GitFailed
     */
    public function entry(CodeReader $reader, Review $review, RepoPath $path): TreeEntry
    {
        $entry = $reader->entryAt($review->getCommitSha(), $path) ?? throw new NotFound('No such path at the review commit.');

        return ObjectType::Blob === $entry->type ? $entry : throw InvalidInput::field('path', 'Must name a file or a symlink, not a directory or a submodule.');
    }

    /**
     * Reads only the bytes of the binary check of a blob above {@see TreeEntry::MAX_RENDERABLE_BYTES}, and no byte in
     * Browser only (D114).
     *
     * @param TreeEntry $entry From {@see entry()}
     *
     * @throws GitFailed
     */
    public function get(CodeReader $reader, Review $review, TreeEntry $entry): FileContent
    {
        $blobSha = BlobSha::fromString($entry->objectId);
        $sizeBytes = $entry->sizeBytes ?? throw new \LogicException("The blob {$blobSha} has no size.");
        $readsCode = CodeAccess::Browser !== $review->getRepository()->getCodeAccess();
        $blob = !$readsCode || $sizeBytes > TreeEntry::MAX_RENDERABLE_BYTES ? null : $reader->readBlob($blobSha);

        return new FileContent(
            $review->getId(),
            $review->getCommitSha(),
            RepoPath::fromString($entry->path),
            $blobSha,
            $entry->mode,
            $entry->isSymlink(),
            $sizeBytes,
            $readsCode ? $blob?->isBinary() ?? self::isBinaryAtStart($reader, $blobSha) : null,
            $blob,
        );
    }

    /**
     * @throws GitFailed
     */
    private static function isBinaryAtStart(CodeReader $reader, BlobSha $blobSha): bool
    {
        $stream = $reader->openBlob($blobSha) ?? throw new \LogicException("The blob {$blobSha} is not in the repository.");
        $stream->close();

        return $stream->isBinary();
    }
}
