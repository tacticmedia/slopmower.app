<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Event\CommitLinkedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Tests\Support\Domain\InvalidInputAssertions;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommitLinkedPayloadTest extends KernelTestCase
{
    use InvalidInputAssertions;

    private const string COMMIT = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    private const string REQUEST_A = '01K6B3P2A9QRSTVWXYZ0123456';
    private const string REQUEST_B = '01K6B3P2A9QRSTVWXYZ0123457';

    #[TestDox('Rejects empty or duplicate requestIds')]
    public function testRejectsEmptyOrDuplicateRequestIds(): void
    {
        $a = RequestId::fromString(self::REQUEST_A);
        $b = RequestId::fromString(self::REQUEST_B);

        self::assertInvalidInput(['requestIds'], static fn () => self::pending([]));
        self::assertInvalidInput(['requestIds.2'], static fn () => self::pending([$a, $b, RequestId::fromString(self::REQUEST_A)]));
    }

    #[TestDox('Carries subject and commit time only when available is true')]
    public function testCarriesGitDataOnlyWhenAvailable(): void
    {
        $requestIds = [RequestId::fromString(self::REQUEST_A)];
        $committedAt = new \DateTimeImmutable('2026-09-29T07:44:10Z');

        $available = new CommitLinkedEvent(CommitSha::fromString(self::COMMIT), $requestIds, null, true, 'Use Money', $committedAt)->toPayload();
        $pending = self::pending($requestIds)->toPayload();

        self::assertSame(['Use Money', '2026-09-29T07:44:10Z'], [$available['subject'], $available['committedAt']]);
        self::assertSame([null, null], [$pending['subject'], $pending['committedAt']]);
        self::assertInvalidInput(
            ['subject', 'committedAt'],
            static fn () => new CommitLinkedEvent(CommitSha::fromString(self::COMMIT), $requestIds, null, true, null, null),
        );
        self::assertInvalidInput(
            ['subject', 'committedAt'],
            static fn () => new CommitLinkedEvent(CommitSha::fromString(self::COMMIT), $requestIds, null, false, 'Use Money', $committedAt),
        );
    }

    /**
     * @param list<RequestId> $requestIds
     */
    private static function pending(array $requestIds): CommitLinkedEvent
    {
        return new CommitLinkedEvent(CommitSha::fromString(self::COMMIT), $requestIds, null, false, null, null);
    }
}
