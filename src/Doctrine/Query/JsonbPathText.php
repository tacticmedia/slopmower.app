<?php

declare(strict_types=1);

namespace App\Doctrine\Query;

use Doctrine\ORM\Query\SqlWalker;

/**
 * `JSONB_PATH_TEXT(document, 'key', ...)` is PostgreSQL `document #>> ARRAY['key', ...]`.
 */
final class JsonbPathText extends JsonbPathFunction
{
    public function getSql(SqlWalker $sqlWalker): string
    {
        return $this->textAtPath($sqlWalker);
    }
}
