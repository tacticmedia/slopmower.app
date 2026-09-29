<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Git\RefName;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Domain\Request\Command\MarkWontDo;
use App\Domain\Request\Command\Open;
use App\Domain\Request\Command\Release;
use App\Domain\Request\Command\Reopen;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\Command\Verify;
use App\Domain\Review\Command\CloseReview;
use App\Domain\Review\Command\StartReview;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommandInputTest extends KernelTestCase
{
    use ViolatedFields;

    /**
     * @return iterable<string, array{\Closure(string): string, string}> each closure returns the text that the command keeps
     */
    public static function markdownInputs(): iterable
    {
        $requestId = LifecycleFixtures::requestId();

        yield 'open body' => [static fn (string $text): string => new Open($requestId, LifecycleFixtures::fileAnchor(), $text)->body, 'body'];
        yield 'reply body' => [static fn (string $text): string => new Reply($requestId, CommentId::generate(), $text)->body, 'body'];
        yield 'ready note' => [static fn (string $text): string => new MarkReadyForReview($requestId, $text)->note, 'note'];
        yield 'reopen note' => [static fn (string $text): string => new Reopen($requestId, $text)->note, 'note'];
    }

    /**
     * @param \Closure(string): string $build
     */
    #[DataProvider('markdownInputs')]
    #[TestDox('Rejects a body or required note that is empty, whitespace only, too long, holds U+0000, or is not UTF-8')]
    public function testRejectsAnInvalidMarkdownText(\Closure $build, string $field): void
    {
        foreach (['', " \t\n\u{00A0}\u{3000}", str_repeat('a', 65537), "a\0b", "a\xFFb"] as $text) {
            self::assertSame([$field], self::violatedFields(static fn (): string => $build($text)));
        }
    }

    /**
     * @param \Closure(string): string $build
     */
    #[DataProvider('markdownInputs')]
    #[TestDox('Accepts a body or required note of 1 to 65,536 characters, counted as characters')]
    public function testAcceptsAMarkdownTextWithinTheLimits(\Closure $build, string $field): void
    {
        foreach (['x', str_repeat("\u{00E9}", 65536)] as $text) {
            self::assertSame($text, $build($text), $field);
        }
    }

    /**
     * @return iterable<string, array{\Closure(?string): ?string}> each closure returns the note that the command keeps
     */
    public static function optionalNoteInputs(): iterable
    {
        $requestId = LifecycleFixtures::requestId();

        yield 'release note' => [static fn (?string $note): ?string => new Release($requestId, $note)->note];
        yield 'verify note' => [static fn (?string $note): ?string => new Verify($requestId, $note)->note];
        yield 'link_commits note' => [static fn (?string $note): ?string => new LinkCommits(LifecycleFixtures::sha(), [$requestId], $note)->note];
        yield 'close note' => [static fn (?string $note): ?string => new CloseReview($note)->note];
    }

    /**
     * @param \Closure(?string): ?string $build
     */
    #[DataProvider('optionalNoteInputs')]
    #[TestDox('Accepts an optional note that is null, empty, or 65,536 characters, and rejects U+0000 and 65,537 characters')]
    public function testChecksAnOptionalNote(\Closure $build): void
    {
        foreach ([null, '', ' ', str_repeat('a', 65536)] as $note) {
            self::assertSame($note, $build($note));
        }
        foreach (["a\0", str_repeat('a', 65537)] as $note) {
            self::assertSame(['note'], self::violatedFields(static fn (): ?string => $build($note)));
        }
    }

    #[TestDox('Rejects a wont_do reason over 65,536 characters or with U+0000')]
    public function testRejectsAnOversizedOrNulReason(): void
    {
        foreach ([str_repeat('a', 65537), str_repeat('a', 20)."\0"] as $reason) {
            self::assertSame(['reason'], self::violatedFields(static fn (): MarkWontDo => new MarkWontDo(LifecycleFixtures::requestId(), $reason)));
        }
    }

    /**
     * @return iterable<string, array{list<RequestId>}>
     */
    public static function invalidRequestIdLists(): iterable
    {
        yield 'empty' => [[]];
        yield 'duplicate' => [[LifecycleFixtures::requestId(), LifecycleFixtures::requestId()]];
        yield '51 IDs' => [array_map(static fn (): RequestId => RequestId::generate(), range(1, 51))];
    }

    /**
     * @param list<RequestId> $requestIds
     */
    #[DataProvider('invalidRequestIdLists')]
    #[TestDox('Rejects an empty, duplicate, or oversized requestIds list')]
    public function testRejectsAnInvalidRequestIdList(array $requestIds): void
    {
        self::assertSame(['requestIds'], self::violatedFields(static fn (): LinkCommits => new LinkCommits(LifecycleFixtures::sha(), $requestIds)));
    }

    #[TestDox('Accepts 50 request IDs')]
    public function testAccepts50RequestIds(): void
    {
        $requestIds = array_map(static fn (): RequestId => RequestId::generate(), range(1, 50));

        self::assertSame($requestIds, new LinkCommits(LifecycleFixtures::sha(), $requestIds)->requestIds);
    }

    #[TestDox('Rejects a review title outside 1 to 200 characters or with U+0000')]
    public function testChecksTheReviewTitle(): void
    {
        $start = static fn (string $title): StartReview => new StartReview($title, RefName::fromString('main'), LifecycleFixtures::sha(LifecycleFixtures::REVIEW_COMMIT));

        self::assertSame(str_repeat("\u{00E9}", 200), $start(str_repeat("\u{00E9}", 200))->title);
        foreach (['', str_repeat('a', 201), "main\0"] as $title) {
            self::assertSame(['title'], self::violatedFields(static fn (): StartReview => $start($title)));
        }
    }
}
