<?php

declare(strict_types=1);

namespace App\Source;

use App\Domain\Error\InvalidInput;
use App\Domain\Git\RefName;
use App\Domain\Repository\RepositoryName;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

/**
 * Every name in the directory is untrusted. A candidate and its `.git` are not symlinks, and the real path of the
 * candidate has the directory as its parent. A bare candidate also has a `HEAD`, `objects`, and `refs` that are not
 * symlinks. The entries inside `.git`, `objects`, and `refs` are not checked.
 */
final class LocalRepositoryDiscovery
{
    public const int MAX_CANDIDATES = 1000;

    private const int MAX_HEAD_BYTES = 1024;

    private readonly ?string $directory;

    public function __construct(
        #[Autowire(env: 'APP_LOCAL_REPOSITORIES_DIR')]
        string $localRepositoriesDir,
        #[Autowire(param: 'kernel.project_dir')]
        string $projectDir,
    ) {
        $this->directory = '' === $localRepositoriesDir ? null : Path::makeAbsolute($localRepositoriesDir, $projectDir);
    }

    public function isEnabled(): bool
    {
        return null !== $this->root();
    }

    /**
     * Empty when local repositories are off. The limit and `truncated` count only the names that $acceptsName accepts.
     *
     * @param (\Closure(string): bool)|null $acceptsName
     */
    public function list(?\Closure $acceptsName = null, int $limit = self::MAX_CANDIDATES): LocalRepositoryListing
    {
        $root = $this->root();

        if (null === $root) {
            return new LocalRepositoryListing([], false);
        }

        $names = self::entries($root);
        sort($names, \SORT_STRING);
        $candidates = [];

        foreach ($names as $name) {
            $candidate = null === $acceptsName || $acceptsName($name) ? $this->candidate($root, $name) : null;

            if (null === $candidate) {
                continue;
            }

            if ($limit === count($candidates)) {
                return new LocalRepositoryListing($candidates, true);
            }

            $candidates[] = $candidate;
        }

        return new LocalRepositoryListing($candidates, false);
    }

    /**
     * Applies the rules of {@see list()} to one name, without the 1,000-item limit. The name must be an exact entry:
     * a case-insensitive mount also resolves a name that differs only in case.
     */
    public function find(string $directory): ?LocalRepositoryCandidate
    {
        $root = $this->root();

        return null !== $root && in_array($directory, self::entries($root), true) ? $this->candidate($root, $directory) : null;
    }

    /**
     * @return list<string>
     */
    private static function entries(string $root): array
    {
        $names = scandir($root, \SCANDIR_SORT_NONE);

        return false === $names ? [] : $names;
    }

    /**
     * @return string|null The real path of the directory, null when local repositories are off
     */
    private function root(): ?string
    {
        if (null === $this->directory || !is_dir($this->directory) || !is_readable($this->directory)) {
            return null;
        }

        $root = realpath($this->directory);

        return false === $root ? null : $root;
    }

    private function candidate(string $root, string $name): ?LocalRepositoryCandidate
    {
        try {
            RepositoryName::local($name);
        } catch (InvalidInput) {
            return null;
        }

        $path = Path::join($root, $name);
        $realPath = self::isDirectory($path) ? realpath($path) : false;

        if (false === $realPath || dirname($realPath) !== $root) {
            return null;
        }

        if (self::isDirectory("{$realPath}/.git")) {
            return new LocalRepositoryCandidate($name, false, self::headBranch("{$realPath}/.git/HEAD"), $realPath);
        }

        if (self::isFile("{$realPath}/HEAD") && self::isDirectory("{$realPath}/objects") && self::isDirectory("{$realPath}/refs")) {
            return new LocalRepositoryCandidate($name, true, self::headBranch("{$realPath}/HEAD"), $realPath);
        }

        return null;
    }

    private static function headBranch(string $headFile): ?string
    {
        $head = self::isFile($headFile) && is_readable($headFile) ? file_get_contents($headFile, length: self::MAX_HEAD_BYTES) : false;

        if (false === $head || 1 !== preg_match('~^ref: refs/heads/(\S+)\n?$~D', $head, $match)) {
            return null;
        }

        try {
            return RefName::fromString($match[1])->toString();
        } catch (InvalidInput) {
            return null;
        }
    }

    private static function isDirectory(string $path): bool
    {
        return !is_link($path) && is_dir($path);
    }

    /**
     * A FIFO named HEAD would block a read, and is_file() is false for it.
     */
    private static function isFile(string $path): bool
    {
        return !is_link($path) && is_file($path);
    }
}
