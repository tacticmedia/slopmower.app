<?php

declare(strict_types=1);

namespace App\Domain\Anchor;

use App\Domain\Error\InvalidInput;
use App\Domain\Error\Violation;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;

/**
 * Where a request points at the review commit.
 */
final readonly class Anchor
{
    private function __construct(
        public AnchorKind $kind,
        public RepoPath $path,
        public CommitSha $commitSha,
        public BlobSha $blobSha,
        public ?LineRange $range,
        public ?SymbolRef $symbol,
        public ?ContentSnapshot $snapshot,
    ) {
    }

    public static function file(RepoPath $path, CommitSha $commitSha, BlobSha $blobSha): self
    {
        return self::fromParts(AnchorKind::File, $path, $commitSha, $blobSha, null, null, null);
    }

    public static function line(RepoPath $path, CommitSha $commitSha, BlobSha $blobSha, LineRange $range, ContentSnapshot $snapshot): self
    {
        return self::fromParts(AnchorKind::Line, $path, $commitSha, $blobSha, $range, null, $snapshot);
    }

    public static function range(RepoPath $path, CommitSha $commitSha, BlobSha $blobSha, LineRange $range, ContentSnapshot $snapshot): self
    {
        return self::fromParts(AnchorKind::Range, $path, $commitSha, $blobSha, $range, null, $snapshot);
    }

    public static function symbol(RepoPath $path, CommitSha $commitSha, BlobSha $blobSha, LineRange $range, SymbolRef $symbol, ContentSnapshot $snapshot): self
    {
        return self::fromParts(AnchorKind::Symbol, $path, $commitSha, $blobSha, $range, $symbol, $snapshot);
    }

    /**
     * Rebuilds a stored anchor and checks the rules of its kind.
     */
    public static function fromParts(
        AnchorKind $kind,
        RepoPath $path,
        CommitSha $commitSha,
        BlobSha $blobSha,
        ?LineRange $range,
        ?SymbolRef $symbol,
        ?ContentSnapshot $snapshot,
        string $field = 'anchor',
    ): self {
        $violations = [...self::rangeViolations($kind, $range, $field), ...self::snapshotViolations($kind, $range, $snapshot, $field)];
        if ((AnchorKind::Symbol === $kind) !== (null !== $symbol)) {
            $violations[] = new Violation($field.'.symbol', AnchorKind::Symbol === $kind ? 'is required for a symbol anchor' : 'must be null for a '.$kind->value.' anchor');
        }
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }

        return new self($kind, $path, $commitSha, $blobSha, $range, $symbol, $snapshot);
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->path->equals($other->path)
            && $this->commitSha->equals($other->commitSha)
            && $this->blobSha->equals($other->blobSha)
            && (null === $this->range ? null === $other->range : null !== $other->range && $this->range->equals($other->range))
            && (null === $this->symbol ? null === $other->symbol : null !== $other->symbol && $this->symbol->equals($other->symbol))
            && (null === $this->snapshot ? null === $other->snapshot : null !== $other->snapshot && $this->snapshot->equals($other->snapshot));
    }

    /**
     * @return list<Violation>
     */
    private static function rangeViolations(AnchorKind $kind, ?LineRange $range, string $field): array
    {
        return match (true) {
            AnchorKind::File === $kind && null !== $range => [new Violation($field.'.range', 'must be null for a file anchor')],
            AnchorKind::File !== $kind && null === $range => [new Violation($field.'.range', 'is required for a '.$kind->value.' anchor')],
            AnchorKind::Line === $kind && !$range->isSingleLine() => [new Violation($field.'.range.endLine', 'must equal startLine for a line anchor')],
            AnchorKind::Range === $kind && $range->isSingleLine() => [new Violation($field.'.range.endLine', 'must be after startLine for a range anchor')],
            default => [],
        };
    }

    /**
     * @return list<Violation>
     */
    private static function snapshotViolations(AnchorKind $kind, ?LineRange $range, ?ContentSnapshot $snapshot, string $field): array
    {
        return match (true) {
            AnchorKind::File === $kind && null !== $snapshot => [new Violation($field.'.snapshot', 'must be null for a file anchor')],
            AnchorKind::File !== $kind && null === $snapshot => [new Violation($field.'.snapshot', 'is required for a '.$kind->value.' anchor')],
            null !== $range && null !== $snapshot && $snapshot->lineCount !== $range->lineCount() => [new Violation($field.'.snapshot.lineCount', 'must equal the line count of range')],
            default => [],
        };
    }
}
