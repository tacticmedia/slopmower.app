<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Application\Query\RepositoryView;
use App\Domain\Repository\RepositorySource;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * repository.schema.json.
 */
final class RepositoryNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof RepositoryView);
        $repository = $data->repository;
        $name = $repository->getName()->toString();
        $isGitHub = RepositorySource::Github === $repository->getSource();

        $normalized = $this->normalizer->normalize([
            'id' => $repository->getId(),
            'source' => $repository->getSource(),
            'name' => $repository->getName(),
            // The owner and the page follow the name, which each sign-in updates.
            'github' => $isGitHub ? [
                'repositoryId' => $repository->getGithubRepositoryId(),
                'installationId' => $repository->getGithubInstallationId(),
                'owner' => explode('/', $name, 2)[0],
                'private' => $repository->isGithubPrivate(),
                'htmlUrl' => "https://github.com/{$name}",
            ] : null,
            'local' => $isGitHub ? null : ['directory' => $repository->getLocalDirectory()],
            'defaultBranch' => $repository->getDefaultBranch(),
            'access' => $repository->getAccess(),
            'sync' => [
                'state' => $repository->getSyncState(),
                'lastFetchedAt' => $repository->getLastFetchedAt(),
                'lastError' => $repository->getLastError(),
                'defaultBranchHead' => $repository->getDefaultBranchHead(),
            ],
            'openReviewCount' => $data->openReviewCount,
            'createdAt' => $repository->getCreatedAt(),
        ], $format, $context);
        \assert(\is_array($normalized));

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof RepositoryView;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [RepositoryView::class => true];
    }
}
