<?php

declare(strict_types=1);

namespace App\Source;

use App\Domain\Repository\RepositoryName;

/**
 * D38: the installation token reaches git only as an extraheader in GIT_CONFIG_* variables, as actions/checkout sends
 * it. The key names github.com, so a changed base URL never receives the token.
 */
final readonly class GitHubFetchCommand
{
    public const string SUSPENDED = 'GitHub App installation suspended';

    private const string EXTRAHEADER_KEY = 'http.https://github.com/.extraheader';

    public ?string $uploadPack;

    /**
     * @param array<string, string> $secretConfiguration
     */
    private function __construct(
        public string $url,
        #[\SensitiveParameter]
        public array $secretConfiguration,
    ) {
        $this->uploadPack = null;
    }

    /**
     * @param string $baseUrl `https://github.com`, or a directory URL in tests
     */
    public static function forRepository(string $baseUrl, RepositoryName $name, #[\SensitiveParameter] string $token): self
    {
        return new self(
            rtrim($baseUrl, '/')."/{$name}.git",
            [self::EXTRAHEADER_KEY => 'AUTHORIZATION: basic '.base64_encode("x-access-token:{$token}")],
        );
    }
}
