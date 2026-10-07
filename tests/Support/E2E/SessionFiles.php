<?php

declare(strict_types=1);

namespace App\Tests\Support\E2E;

use PHPUnit\Framework\Attributes\Before;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The session files of the e2e site, under the framework.session save_path of the e2e environment.
 */
trait SessionFiles
{
    #[Before]
    protected function removeSessionFiles(): void
    {
        new Filesystem()->remove(self::sessionFiles());
    }

    /**
     * @return list<bool> per session file, whether it holds a security token
     */
    protected static function sessionsHoldSecurityToken(): array
    {
        return array_map(static fn (string $file): bool => str_contains((string) file_get_contents($file), '_security_web'), self::sessionFiles());
    }

    /**
     * @return list<string>
     */
    private static function sessionFiles(): array
    {
        return glob(\dirname(__DIR__, 3).'/var/sessions/e2e/sess_*') ?: [];
    }
}
