<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Architecture section 6 and docs/specs/rest-api-requests.md section 1. Each state has one available link, and the claiming agent
 * holds the in_progress request. Inputs are valid: link_commits names a new commit, unlink_commit the linked one.
 */
final class TransitionMatrixTest extends KernelTestCase
{
    private const string T = 'invalid_transition';
    private const string COMMENTED = 'request.commented';
    private const string CLAIMED = 'request.claimed';
    private const string RELEASED = 'request.released';
    private const string LINKED = 'commit.linked';
    private const string UNLINKED = 'commit.unlinked';
    private const string READY = 'request.ready_for_review';
    private const string WONT_DO = 'request.wont_do';
    private const string VERIFIED = 'request.verified';
    private const string REOPENED = 'request.reopened';

    /**
     * Columns: open, in_progress, ready_for_review, verified, reopened, wont_do by agent, wont_do by user.
     */
    private const array MATRIX = [
        'reply' => [
            'user' => [self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED],
            'claiming agent' => [self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED],
            'another agent' => [self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED, self::COMMENTED],
        ],
        'claim' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::CLAIMED, 'no event', self::T, self::T, self::CLAIMED, self::T, self::T],
            'another agent' => [self::CLAIMED, self::T, self::T, self::T, self::CLAIMED, self::T, self::T],
        ],
        'release' => [
            'user' => [self::T, self::RELEASED, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::RELEASED, self::T, self::T, self::T, self::T, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'link_commits' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::LINKED, self::T, self::T, self::T, self::T, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'unlink_commit' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::UNLINKED, self::T, self::T, self::T, self::T, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'mark_ready_for_review' => [
            'user' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'claiming agent' => [self::T, self::READY, self::T, self::T, self::T, self::T, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'mark_wont_do' => [
            'user' => [self::WONT_DO, self::WONT_DO, self::T, self::T, self::WONT_DO, self::T, self::T],
            'claiming agent' => [self::WONT_DO, self::WONT_DO, self::T, self::T, self::WONT_DO, self::T, self::T],
            'another agent' => [self::WONT_DO, self::T, self::T, self::T, self::WONT_DO, self::T, self::T],
        ],
        'verify' => [
            'user' => [self::T, self::T, self::VERIFIED, self::T, self::T, self::VERIFIED, self::T],
            'claiming agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
        'reopen' => [
            'user' => [self::T, self::T, self::REOPENED, self::REOPENED, self::T, self::REOPENED, self::REOPENED],
            'claiming agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T],
            'another agent' => [self::T, self::T, self::T, self::T, self::T, self::T, self::T],
        ],
    ];

    #[TestDox('Command $command by $caller on $state gives $outcome')]
    #[DataProvider('cases')]
    public function testTransition(string $command, string $caller, string $state, string $outcome): void
    {
        $scenario = 'none' === $state ? new RequestScenario() : RequestScenario::inState($state);

        self::assertSame($outcome, $scenario->outcome($scenario->validCommand($command), RequestScenario::caller($caller)));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function cases(): iterable
    {
        $openOutcomes = ['user' => 'request.opened', 'claiming agent' => 'LogicException', 'another agent' => 'LogicException'];

        foreach ($openOutcomes as $caller => $outcome) {
            yield "open by {$caller} on none" => ['open', $caller, 'none', $outcome];
        }

        foreach (self::MATRIX as $command => $rows) {
            foreach ($rows as $caller => $outcomes) {
                foreach (RequestScenario::STATES as $column => $state) {
                    yield "{$command} by {$caller} on {$state}" => [$command, $caller, $state, $outcomes[$column]];
                }
            }
        }
    }
}
