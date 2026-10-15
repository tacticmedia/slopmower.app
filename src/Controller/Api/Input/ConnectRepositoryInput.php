<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Repository\RepositorySource;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/ConnectRepositoryInput. The directory rules are in
 * {@see \App\Application\ConnectLocalRepository::connect()}.
 */
final readonly class ConnectRepositoryInput
{
    public function __construct(
        #[Assert\NotNull]
        public ?RepositorySource $source = null,
        #[Assert\When(expression: static function (self $input): bool {
            return RepositorySource::Github === $input->source;
        }, constraints: [new Assert\NotNull()])]
        #[Assert\Positive]
        public ?int $githubRepositoryId = null,
        #[Assert\When(expression: static function (self $input): bool {
            return RepositorySource::Local === $input->source;
        }, constraints: [new Assert\NotNull()])]
        public ?string $directory = null,
    ) {
    }
}
