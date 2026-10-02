<?php

declare(strict_types=1);

namespace App\Index;

use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SymbolKind;
use App\Entity\Symbol;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Runs after the NameResolver of the same traverser. One instance per file.
 */
final class PhpSymbolCollector extends NodeVisitorAbstract
{
    /** @var list<ExtractedSymbol> */
    private array $symbols = [];

    /** @var list<string> FQNs of the enclosing class-likes */
    private array $classLikes = [];

    /**
     * @return list<ExtractedSymbol> In document order
     */
    public function symbols(): array
    {
        return $this->symbols;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Stmt\ClassLike) {
            return $this->enterClassLike($node);
        }

        if ($node instanceof Stmt\Function_) {
            $this->add(SymbolKind::Function, "{$node->namespacedName}()", $node->name->toString(), null, $node);
        } elseif ($node instanceof Stmt\Const_) {
            foreach ($node->consts as $constant) {
                $this->add(SymbolKind::Constant, (string) $constant->namespacedName, $constant->name->toString(), null, $node);
            }
        }

        $parent = array_last($this->classLikes);

        if (null !== $parent) {
            $this->addMember($parent, $node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Stmt\ClassLike && null !== $node->name) {
            array_pop($this->classLikes);
        }

        return null;
    }

    /**
     * An anonymous class has no FQN, so neither have its members.
     */
    private function enterClassLike(Stmt\ClassLike $node): ?int
    {
        if (null === $node->name) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        $kind = match (true) {
            $node instanceof Stmt\Interface_ => SymbolKind::Interface,
            $node instanceof Stmt\Trait_ => SymbolKind::Trait,
            $node instanceof Stmt\Enum_ => SymbolKind::Enum,
            default => SymbolKind::Class_,
        };
        $fqn = (string) $node->namespacedName;
        $this->add($kind, $fqn, $node->name->toString(), null, $node);
        $this->classLikes[] = $fqn;

        return null;
    }

    private function addMember(string $parent, Node $node): void
    {
        if ($node instanceof Stmt\ClassMethod) {
            $this->add(SymbolKind::Method, "{$parent}::{$node->name}()", $node->name->toString(), $parent, $node);

            foreach ($node->params as $param) {
                if ($param->isPromoted() && $param->var instanceof Node\Expr\Variable && \is_string($param->var->name)) {
                    $this->add(SymbolKind::Property, "{$parent}::\${$param->var->name}", $param->var->name, $parent, $param);
                }
            }
        } elseif ($node instanceof Stmt\Property) {
            foreach ($node->props as $property) {
                $this->add(SymbolKind::Property, "{$parent}::\${$property->name}", $property->name->toString(), $parent, $node);
            }
        } elseif ($node instanceof Stmt\ClassConst) {
            foreach ($node->consts as $constant) {
                $this->add(SymbolKind::ClassConstant, "{$parent}::{$constant->name}", $constant->name->toString(), $parent, $node);
            }
        } elseif ($node instanceof Stmt\EnumCase) {
            $this->add(SymbolKind::EnumCase, "{$parent}::{$node->name}", $node->name->toString(), $parent, $node);
        }
    }

    /**
     * Leaves out a symbol whose FQN JSON cannot carry or symbol.schema.json refuses.
     *
     * @param Node $node Its lines include the attributes. A statement with several items gives each item its lines.
     */
    private function add(SymbolKind $kind, string $fqn, string $name, ?string $parentFqn, Node $node): void
    {
        if (!mb_check_encoding($fqn, 'UTF-8') || mb_strlen($fqn) > Symbol::MAX_FQN_CHARACTERS) {
            return;
        }

        $declarationStartLine = $node->getStartLine();
        $docStartLine = $node->getDocComment()?->getStartLine();
        $range = LineRange::of(min($docStartLine ?? $declarationStartLine, $declarationStartLine), $node->getEndLine());
        $this->symbols[] = new ExtractedSymbol($kind, $fqn, $name, $parentFqn, $range, $declarationStartLine, $docStartLine);
    }
}
