<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Request\Awaiting;
use App\Domain\Request\RequestStatus;

/**
 * The filters and the sort of Q1.
 */
final readonly class RequestFilter
{
    /**
     * @param list<RequestStatus> $statuses   Empty for every status
     * @param list<Awaiting>      $awaiting   Empty for every value
     * @param string|null         $pathPrefix A prefix of `anchor.path`
     */
    public function __construct(
        public array $statuses = [],
        public array $awaiting = [],
        public ?string $pathPrefix = null,
        public ?bool $outdated = null,
        public RequestSort $sort = RequestSort::Created,
    ) {
    }

    /**
     * The filters that a cursor belongs to. List values are sorted, so their order in the query does not matter.
     *
     * @return array<string, string|bool|null>
     */
    public function cursorFilters(): array
    {
        return [
            'status' => self::canonical($this->statuses),
            'awaiting' => self::canonical($this->awaiting),
            'path' => $this->pathPrefix,
            'outdated' => $this->outdated,
            'sort' => $this->sort->value,
        ];
    }

    /**
     * @param list<\BackedEnum> $values
     */
    private static function canonical(array $values): ?string
    {
        $strings = array_unique(array_map(static fn (\BackedEnum $value): string => (string) $value->value, $values));
        sort($strings);

        return [] === $strings ? null : implode(',', $strings);
    }
}
