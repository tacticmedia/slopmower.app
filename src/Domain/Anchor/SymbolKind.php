<?php

declare(strict_types=1);

namespace App\Domain\Anchor;

enum SymbolKind: string
{
    case Class_ = 'class';
    case Interface = 'interface';
    case Trait = 'trait';
    case Enum = 'enum';
    case Function = 'function';
    case Method = 'method';
    case Property = 'property';
    case ClassConstant = 'class_constant';
    case EnumCase = 'enum_case';
    case Constant = 'constant';
    case Struct = 'struct';
    case Type = 'type';
    case Module = 'module';
    case Macro = 'macro';
}
