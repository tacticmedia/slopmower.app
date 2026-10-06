<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Anchor\SymbolRef;
use App\Http\QueryInput;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query of V14: `line` and `column`, or `name`. The path rules are those of {@see \App\Domain\Git\RepoPath}.
 */
final readonly class DeclarationQuery implements QueryInput
{
    private const string LABEL = '[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*';

    public function __construct(
        #[Assert\NotNull]
        public ?string $path = null,
        #[Assert\When(expression: static function (self $query): bool {
            return null === $query->name;
        }, constraints: [new Assert\NotNull()], otherwise: [new Assert\IsNull()])]
        #[Assert\Positive]
        public ?int $line = null,
        #[Assert\When(expression: static function (self $query): bool {
            return null === $query->name;
        }, constraints: [new Assert\NotNull()], otherwise: [new Assert\IsNull()])]
        #[Assert\Positive]
        public ?int $column = null,
        #[Assert\Charset('UTF-8')]
        #[Assert\Length(min: 1, max: SymbolRef::MAX_FQN_CHARACTERS)]
        #[Assert\Regex('/^\\\\?'.self::LABEL.'(?:\\\\'.self::LABEL.')*(?:::(?:\$?'.self::LABEL.'|'.self::LABEL.'\(\)))?$/D', message: 'Must be a PHP name: Type, \Ns\Type, function, CONSTANT, Type::member, Type::method(), or Type::$property.')]
        public ?string $name = null,
    ) {
    }
}
