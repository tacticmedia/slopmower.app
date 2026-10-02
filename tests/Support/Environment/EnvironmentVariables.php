<?php

declare(strict_types=1);

namespace App\Tests\Support\Environment;

use PHPUnit\Framework\Attributes\After;

/**
 * A value set before createClient() or bootKernel() reaches the container of that kernel.
 */
trait EnvironmentVariables
{
    /** @var array<string, array{env: mixed, server: mixed}> */
    private array $originalEnvironmentVariables = [];

    protected function setEnvironmentVariable(string $name, string $value): void
    {
        $this->originalEnvironmentVariables[$name] ??= ['env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null];
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    #[After]
    protected function restoreEnvironmentVariables(): void
    {
        foreach ($this->originalEnvironmentVariables as $name => $originalValues) {
            self::restoreEnvironmentVariable($_ENV, $name, $originalValues['env']);
            self::restoreEnvironmentVariable($_SERVER, $name, $originalValues['server']);
        }

        $this->originalEnvironmentVariables = [];
    }

    /**
     * @param array<string, mixed> $variables
     */
    private static function restoreEnvironmentVariable(array &$variables, string $name, mixed $originalValue): void
    {
        if (null === $originalValue) {
            unset($variables[$name]);
        } else {
            $variables[$name] = $originalValue;
        }
    }
}
