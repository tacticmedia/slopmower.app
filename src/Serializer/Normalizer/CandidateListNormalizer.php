<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Application\Query\CandidateList;
use App\Domain\Repository\RepositorySource;
use App\Entity\GitHubCandidate;
use App\Source\LocalRepositoryCandidate;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * lists.schema.json#/$defs/CandidateList, with the items of repository-candidate.schema.json. The real path of a local
 * candidate stays on the server.
 */
final class CandidateListNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof CandidateList);

        $normalized = $this->normalizer->normalize([
            'items' => [
                ...array_map(static fn (GitHubCandidate $candidate): array => [
                    'source' => RepositorySource::Github,
                    'name' => $candidate->getFullName(),
                    'connectedRepositoryId' => $data->githubConnectedRepositoryIds[$candidate->getGithubRepositoryId()] ?? null,
                    'github' => [
                        'repositoryId' => $candidate->getGithubRepositoryId(),
                        'installationId' => $candidate->getInstallationId(),
                        'accountLogin' => $candidate->getAccountLogin(),
                        'owner' => $candidate->getOwner(),
                        'private' => $candidate->isPrivate(),
                        'defaultBranch' => $candidate->getDefaultBranch(),
                        'htmlUrl' => $candidate->getHtmlUrl(),
                    ],
                    'local' => null,
                ], $data->githubCandidates),
                ...array_map(static fn (LocalRepositoryCandidate $candidate): array => [
                    'source' => RepositorySource::Local,
                    'name' => $candidate->directory,
                    'connectedRepositoryId' => $data->localConnectedRepositoryIds[$candidate->directory] ?? null,
                    'github' => null,
                    'local' => ['directory' => $candidate->directory, 'bare' => $candidate->bare, 'headBranch' => $candidate->headBranch],
                ], $data->localCandidates),
            ],
            'truncated' => $data->truncated,
            'localEnabled' => $data->localEnabled,
            'githubListedAt' => $data->githubListedAt,
            'githubConfigureUrl' => $data->githubConfigureUrl,
        ], $format, $context);
        \assert(\is_array($normalized));

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof CandidateList;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [CandidateList::class => true];
    }
}
