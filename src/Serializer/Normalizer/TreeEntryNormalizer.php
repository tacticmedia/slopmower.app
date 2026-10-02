<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Git\ObjectType;
use App\Git\TreeEntry;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * tree-entry.schema.json.
 */
final class TreeEntryNormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array{path: string, name: string, type: string, mode: string, sha: string, sizeBytes: int|null}
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof TreeEntry);

        return [
            'path' => $data->path,
            'name' => $data->name(),
            'type' => match ($data->type) {
                ObjectType::Tree => 'directory',
                ObjectType::Commit => 'submodule',
                ObjectType::Blob => $data->isSymlink() ? 'symlink' : 'file',
                ObjectType::Tag => throw new \LogicException("A tree has no tag entry: {$data->path}."),
            },
            'mode' => $data->mode,
            'sha' => $data->objectId,
            'sizeBytes' => $data->sizeBytes,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof TreeEntry;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [TreeEntry::class => true];
    }
}
