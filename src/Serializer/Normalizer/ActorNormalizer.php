<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Repository\UserRepository;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * actor.schema.json. The user of a user actor comes from the database for `name` and `avatarUrl`.
 */
final class ActorNormalizer implements NormalizerInterface
{
    private const string SYSTEM_NAME = 'Slopmower';

    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array{kind: string, userId: string|null, agentName: string|null, name: string, avatarUrl: string|null}
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof Actor);
        $user = ActorKind::User === $data->kind && null !== $data->userId
            ? $this->users->find($data->userId) ?? throw new \UnexpectedValueException("The user {$data->userId} of an actor does not exist.") : null;

        return [
            'kind' => $data->kind->value,
            'userId' => $data->userId?->toString(),
            'agentName' => $data->agentName,
            'name' => $user?->getLogin() ?? $data->agentName ?? self::SYSTEM_NAME,
            'avatarUrl' => $user?->getAvatarUrl(),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Actor;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Actor::class => true];
    }
}
