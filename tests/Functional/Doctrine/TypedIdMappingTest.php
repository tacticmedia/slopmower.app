<?php

declare(strict_types=1);

namespace App\Tests\Functional\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TypedIdMappingTest extends KernelTestCase
{
    #[TestDox('No entity maps a column with the ulid type')]
    public function testNoEntityMapsAUlidColumn(): void
    {
        $ulidColumns = [];

        foreach (self::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach ($metadata->fieldMappings as $field => $mapping) {
                if (UlidType::NAME === $mapping->type) {
                    $ulidColumns[] = "{$metadata->getName()}::\${$field}";
                }
            }
        }

        self::assertSame([], $ulidColumns);
    }
}
