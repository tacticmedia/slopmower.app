<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Event\EventType;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Payloads are internal, so no docs/specs schema describes them. The payload table of architecture section 3 does.
 */
final class ArchitecturePayloadTableTest extends KernelTestCase
{
    #[TestDox('Each fixture has the payload fields of the architecture table, in table order')]
    public function testEachFixtureHasThePayloadFieldsOfTheArchitectureTable(): void
    {
        $fixtureFields = [];
        foreach (EventType::cases() as $type) {
            $fixtureFields[$type->value] = array_keys(EventFixtures::payload($type));
        }

        self::assertSame(self::tableFields(), $fixtureFields);
    }

    /**
     * The backticked names of the first sentence of each payload cell, for example `commitSha` and `requestIds: list<RequestId>`.
     *
     * @return array<string, list<string>>
     */
    private static function tableFields(): array
    {
        $architecture = file_get_contents(\dirname(__DIR__, 4).'/docs/architecture.md');
        self::assertIsString($architecture);
        // Section 8 has another table with event type rows.
        self::assertSame(1, preg_match('/^### Events$(.+?)^## 4\. /ms', $architecture, $eventsSection));
        preg_match_all('/^\| `([a-z]+\.[a-z_]+)` \| (.+) \|$/m', $eventsSection[1], $rows, \PREG_SET_ORDER);

        $fields = [];
        foreach ($rows as [, $type, $payload]) {
            preg_match_all('/`(\w+)(?::[^`]*)?`/', preg_split('/\.(?:\s|$)/', $payload, 2)[0] ?? '', $names);
            $fields[$type] = $names[1];
        }

        return $fields;
    }
}
