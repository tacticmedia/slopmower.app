<?php

declare(strict_types=1);

namespace App\Domain\Identity;

final class UniqueIds
{
    /**
     * @param list<Identifier> $ids
     *
     * @return list<array{field: string, message: string}>
     */
    public static function violations(array $ids, string $field, bool $allowEmpty = false): array
    {
        if ([] === $ids && !$allowEmpty) {
            return [['field' => $field, 'message' => 'Must not be empty.']];
        }

        $violations = [];
        $seen = [];

        foreach ($ids as $index => $id) {
            if (isset($seen[$id->toString()])) {
                $violations[] = ['field' => "{$field}.{$index}", 'message' => 'Must not repeat an earlier ID.'];
            }

            $seen[$id->toString()] = true;
        }

        return $violations;
    }
}
