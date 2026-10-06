<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class OpenRequestTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private KernelBrowser $client;

    private User $user;

    private GitRepositoryBuilder $source;

    private string $reviewId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        $this->source->commit(self::codeFiles());
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->reviewId = self::startCodeReview($this->client);
    }

    #[TestDox('Opens line, range, and file requests with 201 and a Location header')]
    public function testOpensLineRangeAndFileRequests(): void
    {
        $anchors = [
            ['kind' => 'line', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 12, 'endLine' => 12]],
            ['kind' => 'range', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 8, 'endLine' => 14]],
            ['kind' => 'file', 'path' => 'assets/logo.png'],
        ];

        foreach ($anchors as $anchor) {
            $response = self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/requests", ['anchor' => $anchor, 'body' => 'Use `Money` instead of `float` for totals.']);
            $opened = self::assertApiResponse($response, 201, 'request.schema.json');

            self::assertSame("/api/v1/requests/{$opened['id']}", $response->headers->get('Location'));
            self::assertSame([$anchor['kind'], $anchor['path'], $anchor['range'] ?? null], [$opened['anchor']['kind'], $opened['anchor']['path'], $opened['anchor']['range']]);
            self::assertSame($opened, self::readRequest($this->client, (string) $opened['id']));
        }
    }

    #[TestDox('Opens a general request without a code location')]
    public function testOpensGeneralRequestWithoutCodeLocation(): void
    {
        $response = self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/requests", ['anchor' => null, 'body' => 'Add a full-screen focus mode.']);

        $opened = self::assertApiResponse($response, 201, 'request.schema.json');
        self::assertSame(['anchor' => null, 'outdated' => null, 'location' => null, 'excerpt' => null], array_intersect_key($opened, array_flip(['anchor', 'outdated', 'location', 'excerpt'])));
    }

    #[TestDox('The snapshot and the blob SHA match git')]
    public function testSnapshotAndBlobShaMatchGit(): void
    {
        $opened = self::openRequest($this->client, $this->reviewId, ['kind' => 'range', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 9, 'endLine' => 12]]);

        $region = ['    $total += 9;', '    $total += 10;', '    $total += 11;', '    $total += 12;'];
        $comparisonForm = implode("\n", ['$total += 9;', '$total += 10;', '$total += 11;', '$total += 12;']);
        self::assertSame([
            'kind' => 'range',
            'path' => self::INVOICE_TOTALS,
            'commitSha' => $this->source->revParse('main'),
            'blobSha' => $this->source->revParse('main:'.self::INVOICE_TOTALS),
            'range' => ['startLine' => 9, 'endLine' => 12],
            'symbol' => null,
            'snapshot' => [
                'lines' => $region,
                'truncated' => false,
                'lineCount' => 4,
                'before' => ['    $total += 6;', '    $total += 7;', '    $total += 8;'],
                'after' => ['    $total += 13;', '    $total += 14;', '    $total += 15;'],
                'normalizedHash' => 'sha256:'.hash('sha256', $comparisonForm),
            ],
        ], $opened['anchor']);
    }

    #[TestDox('A new request is open and awaits the agent')]
    public function testNewRequestIsOpenAndAwaitsAgent(): void
    {
        self::mockTime('2026-09-29T07:10:00Z');

        $opened = self::openRequest($this->client, $this->reviewId, ['kind' => 'line', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 3, 'endLine' => 3]]);

        $author = ['kind' => 'user', 'userId' => $this->user->getId()->toString(), 'agentName' => null, 'name' => 'jan', 'avatarUrl' => null];
        $location = ['target' => 'default_branch', 'targetSha' => $this->source->revParse('main'), 'status' => 'exact', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 3, 'endLine' => 3], 'confidence' => 1, 'reason' => null];
        $expected = [
            'status' => 'open', 'awaiting' => 'agent', 'resolution' => null, 'closed' => false, 'author' => $author,
            'claimedBy' => null, 'claimedAt' => null, 'commentCount' => 0, 'commitCount' => 0, 'pendingCommitCount' => 0, 'outdated' => null,
            'createdAt' => '2026-09-29T07:10:00Z', 'updatedAt' => '2026-09-29T07:10:00Z', 'reviewState' => 'open', 'location' => $location, 'excerpt' => null,
            'commits' => [], 'allowedActions' => ['reply', 'mark_wont_do'],
        ];
        self::assertSame($expected, array_intersect_key($opened, $expected));
        self::assertSame(
            [['seq' => $opened['lastEventSeq'], 'type' => 'request.opened', 'actor' => $author, 'body' => 'Use `Money` instead of `float` for totals.', 'statusAfter' => 'open']],
            array_map(static fn (array $entry): array => array_intersect_key($entry, array_flip(['type', 'actor', 'body', 'statusAfter', 'seq'])), $opened['thread']),
        );
    }

    #[TestDox('The summary is the first non-blank line, trimmed and cut at 200 characters')]
    public function testSummaryIsFirstNonBlankLineCutAt200Characters(): void
    {
        $short = self::openRequest($this->client, $this->reviewId, body: "\n  \t\n  Use `Money`.  \nThe totals lose cents.");
        $long = self::openRequest($this->client, $this->reviewId, body: str_repeat('é', 201));

        self::assertSame('Use `Money`.', $short['summary']);
        self::assertSame(str_repeat('é', 199)."\u{2026}", $long['summary']);
    }
}
