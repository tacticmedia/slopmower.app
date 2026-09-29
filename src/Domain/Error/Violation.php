<?php

declare(strict_types=1);

namespace App\Domain\Error;

final readonly class Violation
{
    /**
     * @param string $field dotted input path, '' for the whole input
     */
    public function __construct(
        public string $field,
        public string $message,
    ) {
    }
}
