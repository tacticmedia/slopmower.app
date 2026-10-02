<?php

declare(strict_types=1);

namespace App\Source;

/**
 * The `-c safe.directory` options go into the `git upload-pack` command that reads the source. Git removes
 * GIT_CONFIG_PARAMETERS from the environment of a local transport, so options of the fetching git do not reach it.
 * Git runs that command through `sh`, and it percent-decodes a `file://` URL.
 */
final readonly class LocalFetchCommand
{
    /** @var array<string, string> */
    public array $secretConfiguration;

    private function __construct(
        public string $url,
        public string $uploadPack,
    ) {
        $this->secretConfiguration = [];
    }

    public static function forDirectory(string $realPath): self
    {
        return new self(
            'file://'.implode('/', array_map(rawurlencode(...), explode('/', $realPath))),
            sprintf('git -c safe.directory=%s -c safe.directory=%s upload-pack', self::shellQuote($realPath), self::shellQuote("{$realPath}/.git")),
        );
    }

    private static function shellQuote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
