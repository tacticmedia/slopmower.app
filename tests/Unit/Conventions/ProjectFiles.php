<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conventions;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

final class ProjectFiles
{
    public static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * @param list<string> $directories relative to the project root, missing ones are skipped
     * @param list<string> $names       Finder name patterns
     *
     * @return array<string, SplFileInfo> keyed by the path relative to the project root
     */
    public static function find(array $directories, array $names): array
    {
        $existingDirectories = array_values(array_filter(
            array_map(static fn (string $directory): string => self::root().'/'.$directory, $directories),
            is_dir(...),
        ));
        if ([] === $existingDirectories) {
            return [];
        }

        $files = [];
        foreach (new Finder()->files()->in($existingDirectories)->name($names)->sortByName() as $file) {
            $files[substr($file->getPathname(), \strlen(self::root()) + 1)] = $file;
        }

        return $files;
    }

    /**
     * PHPUnit runs the files that end in `Test.php`. PSR-4 names their classes.
     *
     * @return array<string, class-string|null> keyed by the path relative to the project root,
     *                                          null when the file does not declare its PSR-4 class
     */
    public static function testClasses(): array
    {
        $classes = [];
        foreach (self::find(['tests'], ['*Test.php']) as $path => $file) {
            $class = 'App\\Tests\\'.str_replace('/', '\\', substr($path, \strlen('tests/'), -\strlen('.php')));
            // Composer includes a file again for a missing class, which redeclares what the file declares.
            require_once $file->getPathname();
            $classes[$path] = class_exists($class, false) ? $class : null;
        }

        return $classes;
    }
}
