<?php

declare(strict_types=1);

namespace App\Tests\Functional\Audit;

use App\Entity\Tenant;
use App\Tenancy\TenantOwned;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

final class RawSqlTenantAuditTest extends KernelTestCase
{
    /** Uppercase keywords mark a literal as SQL, so messages do not count. */
    private const string SQL_KEYWORDS = '/\b(SELECT|INSERT|UPDATE|DELETE|FROM|INTO|JOIN|WHERE|TRUNCATE)\b/';

    /** DBAL methods that run SQL and share their name with other APIs, for example the ORM query builder. */
    private const array TABLE_METHODS = ['insert', 'update', 'delete'];

    #[TestDox('Every SQL literal in a *Sql class that names a tenant table names tenant_id')]
    public function testSqlLiteralsNameTheTenant(): void
    {
        $tables = self::tenantTables();
        foreach (['DELETE FROM comment WHERE id = :id', 'UPDATE Commit_Link SET note = NULL WHERE request_id = :id'] as $statement) {
            self::assertNotSame([], self::literalViolations("<?php const SQL = '{$statement}';", $tables), "The audit misses: {$statement}");
        }

        $violations = [];
        $files = self::sqlClassFiles();

        foreach ($files as $file) {
            foreach (self::literalViolations($file->getContents(), $tables) as $violation) {
                $violations[] = "{$file->getRelativePathname()}:{$violation}";
            }
        }

        self::assertNotSame([], $files);
        self::assertSame([], $violations);
    }

    #[TestDox('No code outside a *Sql class runs raw SQL')]
    public function testNoRawSqlOutsideSqlClasses(): void
    {
        $methods = self::sqlRunningMethods();
        self::assertNotSame([], self::callViolations('<?php $this->connection->executeStatement("TRUNCATE comment");', $methods), 'The scan misses a DBAL call.');
        self::assertSame([], self::callViolations('<?php $queryBuilder->delete(Draft::class, "draft");', $methods), 'The scan flags a DQL delete.');
        $sqlClasses = array_map(static fn (SplFileInfo $file): string => $file->getPathname(), self::sqlClassFiles());
        $violations = [];

        foreach (Finder::create()->files()->in(dirname(__DIR__, 3).'/src')->name('*.php') as $file) {
            if (in_array($file->getPathname(), $sqlClasses, true)) {
                continue;
            }

            foreach (self::callViolations($file->getContents(), $methods) as $violation) {
                $violations[] = "{$file->getRelativePathname()}:{$violation}";
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * @return array<string, string> Table name to the column that holds the tenant
     */
    private static function tenantTables(): array
    {
        $tables = [];

        foreach (self::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (Tenant::class === $metadata->getName()) {
                $tables[$metadata->getTableName()] = 'id';
            } elseif ($metadata->getReflectionClass()->implementsInterface(TenantOwned::class)) {
                $tables[$metadata->getTableName()] = 'tenant_id';
            }
        }

        return $tables;
    }

    /**
     * Only `final` classes named `*Sql` may run raw SQL.
     *
     * @return list<SplFileInfo>
     */
    private static function sqlClassFiles(): array
    {
        $files = [];

        foreach (Finder::create()->files()->in(dirname(__DIR__, 3).'/src')->name('*Sql.php') as $file) {
            $tokens = self::tokens($file->getContents());

            foreach ($tokens as $i => $token) {
                if ($token->is(\T_CLASS) && ($tokens[$i - 1] ?? null)?->is(\T_FINAL)) {
                    $files[] = $file;
                    break;
                }
            }
        }

        return $files;
    }

    /**
     * @param array<string, string> $tables
     *
     * @return list<string> Line and literal of each violation
     */
    private static function literalViolations(string $source, array $tables): array
    {
        $violations = [];

        foreach (self::tokens($source) as $token) {
            if (!$token->is([\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE]) || 1 !== preg_match(self::SQL_KEYWORDS, $token->text)) {
                continue;
            }

            foreach ($tables as $table => $tenantColumn) {
                if (1 === preg_match("/\\b{$table}\\b/i", $token->text) && 1 !== preg_match("/\\b{$tenantColumn}\\b/i", $token->text)) {
                    $violations[] = "{$token->line} {$token->text}";
                }
            }
        }

        return $violations;
    }

    /**
     * The methods of the DBAL connection that take SQL or a table name, and the native query of the ORM.
     *
     * @return list<string> Lower case
     */
    private static function sqlRunningMethods(): array
    {
        $methods = [];

        foreach ([Connection::class => ['sql', 'query', 'table'], EntityManagerInterface::class => ['sql']] as $class => $parameterNames) {
            foreach (new \ReflectionClass($class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (in_array(($method->getParameters()[0] ?? null)?->getName(), $parameterNames, true)) {
                    $methods[] = strtolower($method->getName());
                }
            }
        }

        return $methods;
    }

    /**
     * insert(), update(), and delete() count only in a file that uses the DBAL connection.
     *
     * @param list<string> $methods
     *
     * @return list<string> Line and method of each violation
     */
    private static function callViolations(string $source, array $methods): array
    {
        $usesConnection = str_contains($source, 'Doctrine\DBAL\Connection') || str_contains($source, 'getConnection(') || str_contains($source, '->connection');
        $tokens = self::tokens($source);
        $violations = [];

        foreach ($tokens as $i => $token) {
            $name = strtolower($token->text);
            $isMethodCall = $token->is(\T_STRING)
                && ($tokens[$i - 1] ?? null)?->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR])
                && ($tokens[$i + 1] ?? null)?->is('(');

            if ($isMethodCall && in_array($name, $methods, true) && ($usesConnection || !in_array($name, self::TABLE_METHODS, true))) {
                $violations[] = "{$token->line} {$token->text}";
            }
        }

        return $violations;
    }

    /**
     * @return list<\PhpToken>
     */
    private static function tokens(string $source): array
    {
        return array_values(array_filter(\PhpToken::tokenize($source, \TOKEN_PARSE), static fn (\PhpToken $token): bool => !$token->isIgnorable()));
    }
}
