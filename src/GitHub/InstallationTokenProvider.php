<?php

declare(strict_types=1);

namespace App\GitHub;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Every clone or fetch requests a new token, and only its caller holds it (architecture section 12).
 */
final class InstallationTokenProvider
{
    public const string API = 'https://api.github.com/';

    public const array API_HEADERS = [
        'Accept' => 'application/vnd.github+json',
        'X-GitHub-Api-Version' => '2026-03-10',
    ];

    public function __construct(
        #[Target('github.client')]
        private readonly HttpClientInterface $githubClient,
        private readonly GitHubAppJwtFactory $jwtFactory,
    ) {
    }

    /**
     * A token for one repository with `contents: read`.
     *
     * @throws GitHubRequestFailed
     */
    public function forRepository(int $installationId, int $repositoryId): string
    {
        $status = 0;

        try {
            $response = $this->githubClient->request('POST', self::API."app/installations/{$installationId}/access_tokens", [
                'auth_bearer' => $this->jwtFactory->create(),
                'headers' => self::API_HEADERS,
                'json' => ['repository_ids' => [$repositoryId], 'permissions' => ['contents' => 'read']],
                // Keeps the token out of the profiler storage.
                'extra' => ['trace_content' => false],
            ]);
            $status = $response->getStatusCode();
            $token = 201 === $status ? ($response->toArray()['token'] ?? null) : null;
        } catch (HttpClientException $failure) {
            throw new GitHubRequestFailed('The installation token request failed.', 0, $failure);
        }

        if (!\is_string($token) || '' === $token) {
            throw new GitHubRequestFailed("GitHub answered {$status} to the installation token request.");
        }

        return $token;
    }
}
