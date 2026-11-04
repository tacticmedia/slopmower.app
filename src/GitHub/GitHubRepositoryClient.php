<?php

declare(strict_types=1);

namespace App\GitHub;

use App\Domain\Error\RateLimited;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\RepositoryUnavailableReason;
use App\Domain\Identity\RepositoryId;
use App\Domain\Repository\RepositoryName;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The GitHub API of one repository for {@see GitHubCodeReader}, with an installation token of `contents: read`. Every
 * request sets `buffer: false`, so no body reaches the disk. A failure becomes {@see RepositoryUnavailable} with
 * `fetch_failed`, and a used-up rate limit {@see RateLimited}.
 */
final readonly class GitHubRepositoryClient
{
    public function __construct(
        private RepositoryId $repositoryId,
        private RepositoryName $name,
        private int $installationId,
        private int $githubRepositoryId,
        private HttpClientInterface $client,
        private InstallationTokenProvider $tokens,
        private ClockInterface $clock,
    ) {
    }

    public function token(): string
    {
        try {
            return $this->tokens->forRepository($this->installationId, $this->githubRepositoryId, InstallationTokenProvider::CONTENTS_READ);
        } catch (GitHubRequestFailed $failure) {
            throw $this->unavailable($failure);
        }
    }

    public function url(string $path): string
    {
        return InstallationTokenProvider::API."repos/{$this->name}/{$path}";
    }

    /**
     * Lazy, so several requests can run at once. Pass the response through {@see answered()}.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $options
     */
    public function request(string $method, string $url, array $headers = [], array $options = []): ResponseInterface
    {
        try {
            return $this->client->request($method, $url, [
                'auth_bearer' => $this->token(),
                'headers' => $headers + InstallationTokenProvider::API_HEADERS,
                'buffer' => false,
                // Keeps code out of the profiler storage.
                'extra' => ['trace_content' => false],
            ] + $options);
        } catch (HttpClientException $failure) {
            throw $this->unavailable($failure);
        }
    }

    /**
     * After a 401, the same request again with a new token once, because GitHub can revoke a token before it expires.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $options
     */
    public function answered(ResponseInterface $response, string $method, string $url, array $headers = [], array $options = []): ResponseInterface
    {
        try {
            $status = $response->getStatusCode();
        } catch (HttpClientException $failure) {
            throw $this->unavailable($failure);
        }

        if (401 !== $status) {
            return $response;
        }

        $response->cancel();
        $this->tokens->forget($this->installationId, $this->githubRepositoryId, InstallationTokenProvider::CONTENTS_READ);

        return $this->request($method, $url, $headers, $options);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $options
     */
    public function send(string $method, string $url, array $headers = [], array $options = []): ResponseInterface
    {
        return $this->answered($this->request($method, $url, $headers, $options), $method, $url, $headers, $options);
    }

    /**
     * @param string $path Below the repository, or a full URL
     *
     * @throws RepositoryUnavailable For any status but 2xx
     */
    public function get(string $path): ResponseInterface
    {
        $response = $this->send('GET', str_starts_with($path, 'https://') ? $path : $this->url($path));
        $status = $this->checked($response);

        return $status < 300 ? $response : throw $this->unavailable(new GitHubRequestFailed("GitHub answered {$status} to {$path}."));
    }

    /**
     * @param array<string, string> $headers
     *
     * @return ResponseInterface|null Null for 404 and 422, which GitHub gives for a missing object, and 409 of an empty repository
     */
    public function find(string $path, array $headers = []): ?ResponseInterface
    {
        $response = $this->send('GET', $this->url($path), $headers);
        $status = $this->checked($response);

        if (\in_array($status, [404, 409, 422], true)) {
            $response->cancel();

            return null;
        }

        return $status < 300 ? $response : throw $this->unavailable(new GitHubRequestFailed("GitHub answered {$status} to {$path}."));
    }

    /**
     * @throws RateLimited           For 403 or 429 with no requests left
     * @throws RepositoryUnavailable For a transport failure, 401, another 403, and 5xx
     */
    public function checked(ResponseInterface $response): int
    {
        try {
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
        } catch (HttpClientException $failure) {
            throw $this->unavailable($failure);
        }

        if ((403 === $status || 429 === $status) && '0' === ($headers['x-ratelimit-remaining'][0] ?? null)) {
            $response->cancel();
            $resetUnixSeconds = (int) ($headers['x-ratelimit-reset'][0] ?? 0);

            throw new RateLimited(max(1, $resetUnixSeconds - $this->clock->now()->getTimestamp()), (int) ($headers['x-ratelimit-limit'][0] ?? 0), 0);
        }

        if (401 === $status || 403 === $status || 429 === $status || $status >= 500) {
            $response->cancel();

            throw $this->unavailable(new GitHubRequestFailed("GitHub answered {$status}."));
        }

        return $status;
    }

    public function content(ResponseInterface $response): string
    {
        try {
            return $response->getContent(false);
        } catch (HttpClientException $failure) {
            throw $this->unavailable($failure);
        }
    }

    /**
     * @return array<mixed>
     */
    public function json(ResponseInterface $response): array
    {
        $decoded = json_decode($this->content($response), true);

        return \is_array($decoded) ? $decoded : throw $this->unavailable(new GitHubRequestFailed('GitHub answered without a JSON document.'));
    }

    /**
     * @return \Generator<string>
     */
    public function chunks(ResponseInterface $response): \Generator
    {
        try {
            foreach ($this->client->stream($response) as $chunk) {
                $content = $chunk->getContent();

                if ('' !== $content) {
                    yield $content;
                }
            }
        } catch (HttpClientException $failure) {
            throw $this->unavailable($failure);
        }
    }

    /**
     * @return string|null The URL of the `next` page of the `Link` header
     */
    public function nextPage(ResponseInterface $response): ?string
    {
        $link = $response->getHeaders(false)['link'][0] ?? '';

        return 1 === preg_match('/<([^>]+)>;\s*rel="next"/', $link, $match) ? $match[1] : null;
    }

    private function unavailable(\Throwable $failure): RepositoryUnavailable
    {
        return new RepositoryUnavailable($this->repositoryId, RepositoryUnavailableReason::FetchFailed, $failure);
    }
}
