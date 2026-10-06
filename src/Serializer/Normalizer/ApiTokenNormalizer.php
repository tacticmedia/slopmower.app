<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Application\IssuedApiToken;
use App\Entity\ApiToken;
use Psr\Clock\ClockInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * api-token.schema.json, and ApiTokenWithSecret for an {@see IssuedApiToken}.
 */
final class ApiTokenNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if ($data instanceof IssuedApiToken) {
            return [...$this->normalize($data->token, $format, $context), 'secret' => $data->secret->value];
        }

        \assert($data instanceof ApiToken);
        $repository = $data->getRepository();

        $normalized = $this->normalizer->normalize([
            'id' => $data->getId(),
            'name' => $data->getName(),
            'scope' => $data->getScope()->kind(),
            'repositoryId' => $repository?->getId(),
            'repositoryName' => $repository?->getName(),
            'prefix' => $data->getPrefix(),
            'state' => $data->state($this->clock->now()),
            'createdAt' => $data->getCreatedAt(),
            'expiresAt' => $data->getExpiresAt(),
            'lastUsedAt' => $data->getLastUsedAt(),
            'revokedAt' => $data->getRevokedAt(),
        ], $format, $context);
        \assert(\is_array($normalized));

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof ApiToken || $data instanceof IssuedApiToken;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [ApiToken::class => true, IssuedApiToken::class => true];
    }
}
