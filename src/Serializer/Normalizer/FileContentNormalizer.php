<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Application\Query\FileContent;
use App\Index\FileLanguage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * file-content.schema.json.
 */
final class FileContentNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof FileContent);

        $normalized = $this->normalizer->normalize([
            'reviewId' => $data->reviewId,
            'commitSha' => $data->commitSha,
            'path' => $data->path,
            'blobSha' => $data->blobSha,
            'mode' => $data->mode,
            'type' => $data->symlink ? 'symlink' : 'file',
            'sizeBytes' => $data->sizeBytes,
            'language' => FileLanguage::of($data->path),
            'binary' => $data->binary,
            'tooLarge' => $data->isTooLarge(),
            'renderable' => $data->isRenderable(),
            'lineCount' => $data->lineCount(),
            'symlinkTarget' => $data->symlinkTarget(),
            'rawUrl' => $this->urlGenerator->generate('api_review_raw', ['reviewId' => $data->reviewId, 'path' => $data->path->toString()]),
        ], $format, $context);
        \assert(\is_array($normalized));

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof FileContent;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [FileContent::class => true];
    }
}
