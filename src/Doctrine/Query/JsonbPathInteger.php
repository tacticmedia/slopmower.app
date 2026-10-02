<?php

declare(strict_types=1);

namespace App\Doctrine\Query;

use Doctrine\ORM\Query\SqlWalker;

/**
 * `JSONB_PATH_INTEGER(document, 'key', ...)` is the value of {@see JsonbPathText} cast to an integer.
 */
final class JsonbPathInteger extends JsonbPathFunction
{
    public function getSql(SqlWalker $sqlWalker): string
    {
        return "CAST({$this->textAtPath($sqlWalker)} AS integer)";
    }
}
