<?php

declare(strict_types=1);

namespace App\Domain\Event\Codec;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Identity\UserId;

/**
 * The stored actor. `name` and `avatarUrl` of actor.schema.json come from the users table, not from here.
 */
final class ActorCodec
{
    /**
     * @return array{kind: string, userId: string|null, agentName: string|null}
     */
    public static function encode(Actor $actor): array
    {
        return [
            'kind' => $actor->kind->value,
            'userId' => $actor->userId?->toString(),
            'agentName' => $actor->agentName,
        ];
    }

    public static function decode(PayloadReader $actor): Actor
    {
        return $actor->build(static fn (): Actor => Actor::of(
            $actor->enum('kind', ActorKind::class),
            $actor->nullableId('userId', UserId::class),
            $actor->nullableString('agentName'),
        ));
    }
}
