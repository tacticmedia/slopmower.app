<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Domain\Issue\IssueParty;
use App\Repository\UserRepository;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * issue.schema.json#/$defs/IssueParty. The login of a user comes from the database, as {@see ActorNormalizer} reads it.
 */
final class IssuePartyNormalizer implements NormalizerInterface
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array{kind: string, userId: string|null, name: string|null}
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof IssueParty);
        $user = null === $data->userId ? null : $this->users->find($data->userId) ?? throw new \UnexpectedValueException("The user {$data->userId} of an issue party does not exist.");

        return ['kind' => $data->kind->value, 'userId' => $data->userId?->toString(), 'name' => $user?->getLogin()];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof IssueParty;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [IssueParty::class => true];
    }
}
