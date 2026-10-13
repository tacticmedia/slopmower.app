<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

/**
 * inputs.schema.json#/$defs/AcceptSuggestionInput.
 */
final readonly class AcceptSuggestionInput
{
    /**
     * @param bool|null $requireApproval Null keeps the current value
     */
    public function __construct(
        public ?bool $requireApproval = null,
    ) {
    }
}
