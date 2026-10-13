<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Application\Query\RequestFilter;
use App\Application\Query\RequestSort;
use App\Domain\Error\InvalidInput;
use App\Domain\Request\Awaiting;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\SuggestionState;
use App\Http\QueryInput;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query of Q1. Lists are comma-separated, docs/specs/README.md section 2.1.
 */
final readonly class RequestListQuery implements QueryInput
{
    public function __construct(
        #[Assert\Charset('UTF-8')]
        public ?string $status = null,
        #[Assert\Charset('UTF-8')]
        public ?string $awaiting = null,
        #[Assert\Charset('UTF-8')]
        #[Assert\Length(max: 4096)]
        #[Assert\Regex(pattern: '/^(?!\/)[^\x00]*$/D', message: 'Must not start with "/" or hold a NUL byte.')]
        public ?string $path = null,
        public ?bool $outdated = null,
        public ?SuggestionState $suggestion = null,
        public RequestSort $sort = RequestSort::Created,
        #[Assert\Range(min: 1, max: 200)]
        public int $limit = 50,
        #[Assert\Charset('UTF-8')]
        public ?string $cursor = null,
    ) {
    }

    /**
     * @throws InvalidInput An unknown value in a list
     */
    public function filter(): RequestFilter
    {
        return new RequestFilter(
            self::values($this->status, RequestStatus::class, 'status'),
            self::values($this->awaiting, Awaiting::class, 'awaiting'),
            $this->path,
            $this->outdated,
            $this->suggestion,
            $this->sort,
        );
    }

    /**
     * @template T of RequestStatus|Awaiting
     *
     * @param class-string<T> $enum
     *
     * @return list<T>
     *
     * @throws InvalidInput
     */
    private static function values(?string $list, string $enum, string $field): array
    {
        if (null === $list || '' === $list) {
            return [];
        }

        return array_map(
            static fn (string $value): RequestStatus|Awaiting => $enum::tryFrom($value) ?? throw InvalidInput::field($field, "Holds the unknown value \"{$value}\"."),
            explode(',', $list),
        );
    }
}
