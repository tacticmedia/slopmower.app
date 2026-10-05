<?php

declare(strict_types=1);

namespace App\Source;

use App\Domain\Repository\RepositoryName;

/**
 * What `git fetch` needs to read one source (D24).
 */
final readonly class FetchCommand
{
    private const string EXTRAHEADER_KEY = 'http.https://github.com/.extraheader';

    /**
     * @param array<string, string> $secretConfiguration
     * @param string|null           $uploadPack          The `--upload-pack` command, which reads the source
     */
    private function __construct(
        public string $url,
        #[\SensitiveParameter]
        public array $secretConfiguration = [],
        public ?string $uploadPack = null,
    ) {
    }

    /**
     * D38: the installation token reaches git only as an extraheader in GIT_CONFIG_* variables, as actions/checkout
     * sends it. The key names github.com, so a changed base URL never receives the token.
     *
     * @param string $baseUrl `https://github.com`, or a directory URL in tests
     */
    public static function gitHub(string $baseUrl, RepositoryName $name, #[\SensitiveParameter] string $token): self
    {
        return new self(
            rtrim($baseUrl, '/')."/{$name}.git",
            [self::EXTRAHEADER_KEY => 'AUTHORIZATION: basic '.base64_encode("x-access-token:{$token}")],
        );
    }

    /**
     * The `-c safe.directory` options go into the `git upload-pack` command that reads the source. Git removes
     * GIT_CONFIG_PARAMETERS from the environment of a local transport, so options of the fetching git do not reach it.
     * Git runs that command through `sh`, and it percent-decodes a `file://` URL.
     */
    public static function localDirectory(string $realPath): self
    {
        return new self(
            'file://'.implode('/', array_map(rawurlencode(...), explode('/', $realPath))),
            uploadPack: sprintf('git -c safe.directory=%s -c safe.directory=%s upload-pack', self::shellQuote($realPath), self::shellQuote("{$realPath}/.git")),
        );
    }

    private static function shellQuote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
