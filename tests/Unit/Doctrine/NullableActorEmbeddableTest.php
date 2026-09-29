<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine;

use App\Doctrine\Embeddable\NullableActorEmbeddable;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

final class NullableActorEmbeddableTest extends KernelTestCase
{
    #[TestDox('A user ID without a kind is refused')]
    public function testAUserIdWithoutAKindIsRefused(): void
    {
        $embeddable = NullableActorEmbeddable::of(null);
        new \ReflectionProperty(NullableActorEmbeddable::class, 'userId')->setValue($embeddable, new Ulid());

        $this->expectException(\LogicException::class);

        $embeddable->toActor();
    }
}
