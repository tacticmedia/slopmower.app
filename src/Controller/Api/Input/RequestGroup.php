<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * common.schema.json#/$defs/Group, after trimming. Allows null.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class RequestGroup extends Assert\Compound
{
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank(allowNull: true, normalizer: 'trim'),
            new Assert\Length(max: 80, normalizer: 'trim'),
            new Assert\Regex(pattern: '/[\r\n]/', match: false, message: 'Must be one line, without CR or LF.'),
        ];
    }
}
