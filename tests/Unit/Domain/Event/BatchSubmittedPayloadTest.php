<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Event\BatchSubmitted;
use App\Domain\Event\Codec\EventCodec;
use App\Domain\Event\EventType;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\RequestId;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BatchSubmittedPayloadTest extends KernelTestCase
{
    use ViolatedFields;

    private const string BATCH_ID = '01K6B3P1Z8Y7X6W5V4T3S2R1Q0';
    private const string OPENED_REQUEST_ID = '01K6B3P2A9QRSTVWXYZ0123456';
    private const string COMMENTED_REQUEST_ID = '01K6B3P2A9QRSTVWXYZ0123457';
    private const string COMMENT_ID = '01K6C3C0MMENT0000000000001';

    #[TestDox('Carries the batch ID, the summary, and the request and comment IDs')]
    public function testCarriesTheBatchIdTheSummaryAndTheRequestAndCommentIds(): void
    {
        $fixture = EventFixtures::json(EventType::BatchSubmitted);

        $event = EventCodec::decode('batch.submitted', $fixture);

        self::assertEquals(
            BatchSubmitted::of(
                BatchId::fromString(self::BATCH_ID),
                'First pass over billing.',
                [RequestId::fromString(self::OPENED_REQUEST_ID), RequestId::fromString(self::COMMENTED_REQUEST_ID)],
                [CommentId::fromString(self::COMMENT_ID)],
            ),
            $event,
        );
        self::assertSame($fixture, EventCodec::encode($event));
    }

    #[TestDox('Reads a missing summary as null and accepts a batch without comments')]
    public function testReadsAMissingSummaryAsNullAndAcceptsABatchWithoutComments(): void
    {
        $event = BatchSubmitted::fromPayload(['batchId' => self::BATCH_ID, 'requestIds' => [self::OPENED_REQUEST_ID], 'commentIds' => []]);

        self::assertSame(
            ['batchId' => self::BATCH_ID, 'summary' => null, 'requestIds' => [self::OPENED_REQUEST_ID], 'commentIds' => []],
            $event->toPayload(),
        );
    }

    /**
     * @return iterable<string, array{list<string>, list<string>, list<string>}>
     */
    public static function duplicateIds(): iterable
    {
        yield 'request ID twice' => [[self::OPENED_REQUEST_ID, self::OPENED_REQUEST_ID], [self::COMMENT_ID], ['payload.requestIds']];
        yield 'comment ID twice' => [[self::COMMENTED_REQUEST_ID], [self::COMMENT_ID, strtolower(self::COMMENT_ID)], ['payload.commentIds']];
        yield 'both twice' => [[self::OPENED_REQUEST_ID, self::OPENED_REQUEST_ID], [self::COMMENT_ID, self::COMMENT_ID], ['payload.requestIds', 'payload.commentIds']];
    }

    /**
     * @param list<string> $requestIds
     * @param list<string> $commentIds
     * @param list<string> $expectedFields
     */
    #[DataProvider('duplicateIds')]
    #[TestDox('Rejects a request ID or a comment ID that appears twice')]
    public function testRejectsARequestIdOrACommentIdThatAppearsTwice(array $requestIds, array $commentIds, array $expectedFields): void
    {
        $payload = ['batchId' => self::BATCH_ID, 'summary' => null, 'requestIds' => $requestIds, 'commentIds' => $commentIds];

        self::assertSame($expectedFields, self::violatedFields(static fn (): BatchSubmitted => BatchSubmitted::fromPayload($payload)));
    }
}
