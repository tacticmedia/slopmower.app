<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Application\Query\FileContent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * file-content.schema.json. `language` is an open set of highlighting language names, chosen by file name.
 */
final class FileContentNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const array LANGUAGE_BY_FILE_NAME = [
        'dockerfile' => 'docker',
        'makefile' => 'make',
    ];

    private const array LANGUAGE_BY_EXTENSION = [
        'php' => 'php',
        'twig' => 'twig',
        'js' => 'javascript',
        'mjs' => 'javascript',
        'cjs' => 'javascript',
        'jsx' => 'jsx',
        'ts' => 'typescript',
        'mts' => 'typescript',
        'cts' => 'typescript',
        'tsx' => 'tsx',
        'json' => 'json',
        'md' => 'markdown',
        'markdown' => 'markdown',
        'yaml' => 'yaml',
        'yml' => 'yaml',
        'xml' => 'xml',
        'html' => 'html',
        'htm' => 'html',
        'css' => 'css',
        'scss' => 'scss',
        'sql' => 'sql',
        'sh' => 'shellscript',
        'bash' => 'shellscript',
        'py' => 'python',
        'rb' => 'ruby',
        'go' => 'go',
        'rs' => 'rust',
        'java' => 'java',
        'kt' => 'kotlin',
        'c' => 'c',
        'h' => 'c',
        'cpp' => 'cpp',
        'hpp' => 'cpp',
        'cs' => 'csharp',
        'toml' => 'toml',
        'ini' => 'ini',
        'vue' => 'vue',
        'graphql' => 'graphql',
        'diff' => 'diff',
    ];

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
            'path' => $data->path->toString(),
            'blobSha' => $data->blobSha->toString(),
            'mode' => $data->mode,
            'type' => $data->symlink ? 'symlink' : 'file',
            'sizeBytes' => $data->sizeBytes,
            'language' => self::language($data->path->toString()),
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

    private static function language(string $path): ?string
    {
        $fileName = strtolower(basename($path));
        $dot = strrpos($fileName, '.');

        return self::LANGUAGE_BY_FILE_NAME[$fileName] ?? (false === $dot ? null : self::LANGUAGE_BY_EXTENSION[substr($fileName, $dot + 1)] ?? null);
    }
}
