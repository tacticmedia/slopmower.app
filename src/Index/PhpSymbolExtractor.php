<?php

declare(strict_types=1);

namespace App\Index;

use App\Domain\Git\RepoPath;
use PhpParser\Error;
use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\FirstFindingVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * FQNs: `Ns\Type`, `Ns\Type::method()`, `Ns\Type::$property`, `Ns\Type::CONSTANT`,
 * `Ns\Enum::Case`, `Ns\function()`, `Ns\CONSTANT`. Promoted constructor parameters are properties.
 */
final class PhpSymbolExtractor
{
    private readonly Parser $parser;

    public function __construct()
    {
        $this->parser = new ParserFactory()->createForNewestSupportedVersion();
    }

    public function language(): string
    {
        return 'php';
    }

    public function supports(RepoPath $path): bool
    {
        return str_ends_with(strtolower($path->toString()), '.php');
    }

    /**
     * A syntax error gives `parseError` and no symbols. The NameResolver also throws a parse error, for example for a
     * duplicate `use` alias.
     */
    public function extract(string $source): SymbolTable
    {
        $collector = new PhpSymbolCollector();

        return $this->traverse($source, new NameResolver(), $collector)
            ? new SymbolTable($this->language(), false, $collector->symbols())
            : new SymbolTable($this->language(), true, []);
    }

    /**
     * A method call on a variable needs type inference and gives no reference.
     *
     * @param int $column 1-based, in Unicode characters of the line
     *
     * @return list<Reference> The static name that covers the position, if any
     */
    public function referencesAt(string $source, int $line, int $column): array
    {
        $offset = self::byteOffset($source, $line, $column);

        if (null === $offset) {
            return [];
        }

        $finder = new FirstFindingVisitor(static fn (Node $node): bool => ($node instanceof Name || $node instanceof Identifier) && $node->getStartFilePos() <= $offset && $offset <= $node->getEndFilePos());
        $found = $this->traverse($source, new NameResolver(), new ParentConnectingVisitor(), $finder) ? $finder->getFoundNode() : null;
        $reference = match (true) {
            $found instanceof Name => self::nameReference($found),
            $found instanceof Identifier => self::memberReference($found),
            default => null,
        };

        return null === $reference ? [] : [$reference];
    }

    /**
     * Resolves with the namespace and the `use` imports at the end of the file.
     *
     * @param string $name `Type`, `\Ns\Type`, `namespace\Type`, `function`, `CONSTANT`, `Type::member`, `Type::method()`, or `Type::$property`
     *
     * @return list<Reference> A name without `::` can be a class-like, a function, or a constant
     */
    public function referencesNamed(string $source, string $name): array
    {
        $resolver = new NameResolver();

        if (!$this->traverse($source, $resolver)) {
            return [];
        }

        $context = $resolver->getNameContext();
        $separator = strpos($name, '::');
        $type = false === $separator ? $name : substr($name, 0, $separator);
        $typeName = match (true) {
            str_starts_with($type, '\\') => new FullyQualified(substr($type, 1)),
            str_starts_with(strtolower($type), 'namespace\\') => new Name\Relative(substr($type, strlen('namespace\\'))),
            default => new Name($type),
        };

        try {
            $classFqn = $context->getResolvedClassName($typeName)->toString();
        } catch (Error) {
            return [];
        }

        if (false === $separator) {
            return [
                Reference::classLike($classFqn),
                Reference::function(...self::withFallback($context, $typeName, Stmt\Use_::TYPE_FUNCTION)),
                Reference::constant(...self::withFallback($context, $typeName, Stmt\Use_::TYPE_CONSTANT)),
            ];
        }

        $member = substr($name, $separator + 2);

        return match (true) {
            str_starts_with($member, '$') => [Reference::property($classFqn, substr($member, 1))],
            str_ends_with($member, '()') => [Reference::method($classFqn, substr($member, 0, -2))],
            default => [Reference::method($classFqn, $member), Reference::classConstant($classFqn, $member)],
        };
    }

    /**
     * @return bool False on a syntax error, or a name error of the NameResolver
     */
    private function traverse(string $source, NodeVisitor ...$visitors): bool
    {
        try {
            $statements = $this->parser->parse($source) ?? throw new Error('The parser returned no statements.');
            new NodeTraverser(...$visitors)->traverse($statements);
        } catch (Error) {
            return false;
        }

        return true;
    }

    /**
     * @return int|null Null when the source has no such line or column
     */
    private static function byteOffset(string $source, int $line, int $column): ?int
    {
        $lines = explode("\n", $source);
        $text = $lines[$line - 1] ?? null;

        if (null === $text || mb_strlen($text, 'UTF-8') < $column) {
            return null;
        }

        return array_sum(array_map(strlen(...), array_slice($lines, 0, $line - 1))) + $line - 1 + strlen(mb_substr($text, 0, $column - 1, 'UTF-8'));
    }

    /**
     * Reads the attributes of the NameResolver and the ParentConnectingVisitor.
     */
    private static function nameReference(Name $name): ?Reference
    {
        $parent = $name->getAttribute('parent');

        if ($parent instanceof Expr\FuncCall || $parent instanceof Expr\ConstFetch) {
            $namespaced = $name->getAttribute('namespacedName');
            $fqns = $namespaced instanceof Name ? [$namespaced->toString(), $name->toString()] : [$name->toString()];

            return $parent instanceof Expr\FuncCall ? Reference::function(...$fqns) : Reference::constant(...$fqns);
        }

        if ($parent instanceof Node\UseItem) {
            return self::importReference($name, $parent);
        }

        $classFqn = $parent instanceof Stmt\Namespace_ || $parent instanceof Stmt\GroupUse ? null : self::classFqn($name);

        return null === $classFqn ? null : Reference::classLike($classFqn);
    }

    private static function memberReference(Identifier $member): ?Reference
    {
        $parent = $member->getAttribute('parent');

        if (!($parent instanceof Expr\StaticCall || $parent instanceof Expr\ClassConstFetch || $parent instanceof Expr\StaticPropertyFetch) || $parent->name !== $member || !$parent->class instanceof Name) {
            return null;
        }

        $classFqn = self::classFqn($parent->class);

        return match (true) {
            null === $classFqn => null,
            $parent instanceof Expr\StaticCall => Reference::method($classFqn, $member->toString()),
            $parent instanceof Expr\StaticPropertyFetch => Reference::property($classFqn, $member->toString()),
            'class' === $member->toLowerString() => Reference::classLike($classFqn),
            default => Reference::classConstant($classFqn, $member->toString()),
        };
    }

    private static function importReference(Name $name, Node\UseItem $item): Reference
    {
        $use = $item->getAttribute('parent');
        $type = $item->type | ($use instanceof Stmt\Use_ || $use instanceof Stmt\GroupUse ? $use->type : Stmt\Use_::TYPE_UNKNOWN);
        $fqn = $use instanceof Stmt\GroupUse ? "{$use->prefix}\\{$name}" : $name->toString();

        return match ($type) {
            Stmt\Use_::TYPE_FUNCTION => Reference::function($fqn),
            Stmt\Use_::TYPE_CONSTANT => Reference::constant($fqn),
            default => Reference::classLike($fqn),
        };
    }

    /**
     * `self` and `static` are the enclosing class-like, `parent` is its parent class.
     */
    private static function classFqn(Name $name): ?string
    {
        if (!$name->isSpecialClassName()) {
            return $name->toString();
        }

        $classLike = $name;

        do {
            $classLike = $classLike->getAttribute('parent');
        } while ($classLike instanceof Node && !$classLike instanceof Stmt\ClassLike);

        return match (true) {
            !$classLike instanceof Stmt\ClassLike => null,
            'parent' !== $name->toLowerString() => $classLike->namespacedName?->toString(),
            $classLike instanceof Stmt\Class_ => $classLike->extends?->toString(),
            default => null,
        };
    }

    /**
     * An unqualified function or constant name that no `use` imports resolves in the namespace first, then globally.
     *
     * @param Stmt\Use_::TYPE_FUNCTION|Stmt\Use_::TYPE_CONSTANT $type
     *
     * @return non-empty-list<string>
     */
    private static function withFallback(NameContext $context, Name $name, int $type): array
    {
        $resolved = $context->getResolvedName($name, $type);
        $namespace = $context->getNamespace();

        return null === $resolved && null !== $namespace ? ["{$namespace}\\{$name}", $name->toString()] : [($resolved ?? $name)->toString()];
    }
}
