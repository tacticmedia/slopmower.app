<?php

declare(strict_types=1);

namespace App\Domain\Anchor;

use App\Domain\Error\InvalidInput;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;

/**
 * The per-kind rules of `KindRules` and `AnchorInput` in anchor.schema.json.
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

    public static function of(
        AnchorKind $kind,
        RepoPath $path,
        CommitSha $commitSha,
        BlobSha $blobSha,
        ?LineRange $range,
        ?SymbolRef $symbol,
        ?ContentSnapshot $snapshot,
    ): self {
        $violations = [];
        $isFile = AnchorKind::File === $kind;
        $isSymbol = AnchorKind::Symbol === $kind;

        foreach (['range' => [$range, !$isFile], 'symbol' => [$symbol, $isSymbol], 'snapshot' => [$snapshot, !$isFile]] as $field => [$value, $isRequired]) {
            if ($isRequired && null === $value) {
                $violations[] = ['field' => $field, 'message' => "Is required for a {$kind->value} anchor."];
            } elseif (!$isRequired && null !== $value) {
                $violations[] = ['field' => $field, 'message' => "Must be null for a {$kind->value} anchor."];
            }
        }

        $violations = [...$violations, ...self::rangeViolations($kind, $range)];

        if (null !== $range && null !== $snapshot && $range->lineCount() !== $snapshot->lineCount) {
            $violations[] = ['field' => 'snapshot.lineCount', 'message' => 'Must equal the line count of range.'];
        }

        if ([] !== $violations) {
            throw new InvalidInput($violations);
        }

        return new self($kind, $path, $commitSha, $blobSha, $range, $symbol, $snapshot);
    }

    /**
     * The line count rules of `line` and `range`, which need no file content.
     *
     * @return list<array{field: string, message: string}>
     */
    public static function rangeViolations(AnchorKind $kind, ?LineRange $range): array
    {
        return match (true) {
            null === $range => [],
            AnchorKind::Line === $kind && 1 !== $range->lineCount() => [['field' => 'range.endLine', 'message' => 'Must equal startLine for a line anchor.']],
            AnchorKind::Range === $kind && 1 === $range->lineCount() => [['field' => 'range.endLine', 'message' => 'Must be after startLine for a range anchor.']],
            default => [],
        };
    }
}
