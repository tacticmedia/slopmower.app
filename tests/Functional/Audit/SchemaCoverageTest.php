<?php

declare(strict_types=1);

namespace App\Tests\Functional\Audit;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

/**
 * A schema is a file root with keywords of its own, or a `$defs` entry. The scan counts it as asserted when a string
 * literal in a PHP file under tests/ names it, or names a schema that reaches it through `$ref`. The literal is a whole
 * schema reference, for example "lists.schema.json#/$defs/RepositoryPage", the argument form of every schema assertion.
 */
final class SchemaCoverageTest extends KernelTestCase
{
    private const string SCHEMA_DIRECTORY = __DIR__.'/../../../docs/specs/schemas';

    private const string TEST_DIRECTORY = __DIR__.'/../..';

    /** The argument form of every schema assertion: "lists.schema.json#/$defs/RepositoryPage". */
    private const string REFERENCE = '~^[a-z0-9-]+\.schema\.json(#/\$defs/[A-Za-z0-9]+)?$~';

    /** Root keys that only describe the file. A root with no other key is a container of `$defs`. */
    private const array ANNOTATIONS = ['$schema', '$id', '$defs', '$comment', 'title', 'description'];

    #[TestDox('Every schema in docs/specs/schemas is asserted by at least one test')]
    public function testEverySchemaIsAsserted(): void
    {
        $schemas = self::schemaFiles();
        $pending = self::referencesInTests($schemas);
        $asserted = [];

        while ([] !== $pending) {
            $reference = array_pop($pending);

            if (!isset($asserted[$reference])) {
                $asserted[$reference] = true;
                $pending = [...$pending, ...self::outgoingReferences($schemas, $reference)];
            }
        }

        self::assertNotSame([], $asserted);
        self::assertSame([], array_values(array_diff(self::allSchemas($schemas), array_keys($asserted))));
    }

    /**
     * @return array<string, array<string, mixed>> Decoded schema by file name
     */
    private static function schemaFiles(): array
    {
        $schemas = [];

        foreach (Finder::create()->files()->in(self::SCHEMA_DIRECTORY)->name('*.schema.json') as $file) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($file->getContents(), true, flags: \JSON_THROW_ON_ERROR);
            $schemas[$file->getFilename()] = $decoded;
        }

        return $schemas;
    }

    /**
     * @param array<string, array<string, mixed>> $schemas
     *
     * @return list<string>
     */
    private static function allSchemas(array $schemas): array
    {
        $all = [];

        foreach ($schemas as $file => $schema) {
            if ([] !== array_diff(array_keys($schema), self::ANNOTATIONS)) {
                $all[] = $file;
            }

            foreach (array_keys(self::definitions($schema)) as $name) {
                $all[] = "{$file}#/\$defs/{$name}";
            }
        }

        sort($all);

        return $all;
    }

    /**
     * @param array<string, array<string, mixed>> $schemas
     *
     * @return list<string> The string literals of tests/ that name an existing schema
     */
    private static function referencesInTests(array $schemas): array
    {
        $references = [];

        foreach (Finder::create()->files()->in(self::TEST_DIRECTORY)->name('*.php') as $file) {
            foreach (\PhpToken::tokenize($file->getContents()) as $token) {
                $literal = $token->is(\T_CONSTANT_ENCAPSED_STRING) ? substr($token->text, 1, -1) : '';

                if (1 === preg_match(self::REFERENCE, $literal) && null !== self::resolve($schemas, $literal)) {
                    $references[] = $literal;
                }
            }
        }

        return $references;
    }

    /**
     * @param array<string, array<string, mixed>> $schemas
     *
     * @return list<string> The schemas that the `$ref`s of $reference name, as absolute references
     */
    private static function outgoingReferences(array $schemas, string $reference): array
    {
        $file = explode('#', $reference)[0];
        $schema = self::resolve($schemas, $reference) ?? [];
        unset($schema['$defs']);
        $references = [];

        array_walk_recursive($schema, static function (mixed $value, int|string $key) use ($file, $schemas, &$references): void {
            if ('$ref' !== $key || !\is_string($value)) {
                return;
            }

            $absolute = rtrim(str_starts_with($value, '#') ? $file.$value : $value, '#');
            self::assertNotNull(self::resolve($schemas, $absolute), "{$file} has the unresolvable \$ref {$value}.");
            $references[] = $absolute;
        });

        return $references;
    }

    /**
     * @param array<string, array<string, mixed>> $schemas
     *
     * @return array<string, mixed>|null
     */
    private static function resolve(array $schemas, string $reference): ?array
    {
        if (1 !== preg_match('~^([^#]+)(?:#/\$defs/([A-Za-z0-9]+))?$~', $reference, $match)) {
            return null;
        }

        $schema = $schemas[$match[1]] ?? null;

        return null === $schema || !isset($match[2]) ? $schema : (self::definitions($schema)[$match[2]] ?? null);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, array<string, mixed>>
     */
    private static function definitions(array $schema): array
    {
        /** @var array<string, array<string, mixed>> $definitions */
        $definitions = $schema['$defs'] ?? [];

        return $definitions;
    }
}
