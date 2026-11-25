<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Issue;

use App\Domain\Actor\Actor;
use App\Domain\Error\InvalidInput;
use App\Domain\Identity\UserId;
use App\Domain\Issue\IssueClaim;
use App\Domain\Issue\IssueKey;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The lease of D130 on the issue BILL-12, taken by the session billing-3f9a at 09:00.
 */
final class IssueClaimTest extends KernelTestCase
{
    private const string SESSION = 'billing-3f9a';

    private static UserId $owner;

    public static function setUpBeforeClass(): void
    {
        self::$owner = UserId::generate();
    }

    #[TestDox('While the claim holds, another session of the token and the same session name of another token are refused on session')]
    public function testAnotherHolderIsRefusedWhileTheClaimHolds(): void
    {
        $claim = self::take(null, self::SESSION, '09:00');

        $otherSession = self::refusal($claim, self::agent(), 'billing-77c0');
        $otherToken = self::refusal($claim, Actor::agent(self::$owner, 'claude-code desktop'), self::SESSION);

        self::assertSame([['field' => 'session', 'message' => 'Session billing-3f9a of claude-code laptop holds BILL-12 until 2026-11-25T11:00:00Z. Take another issue.']], $otherSession->details()['violations']);
        self::assertSame('session', $otherToken->details()['violations'][0]['field']);
    }

    #[TestDox('After its end the claim is no claim, and another session takes the issue from that write on')]
    public function testAnotherSessionTakesTheIssueAfterTheEnd(): void
    {
        $claim = self::take(null, self::SESSION, '09:00');

        $taken = self::take($claim, 'billing-77c0', '11:00');

        self::assertNull(IssueClaim::held($claim, self::at('11:00')));
        self::assertSame(['billing-77c0', '11:00', '13:00'], [$taken->session, $taken->since->format('H:i'), $taken->until->format('H:i')]);
    }

    #[TestDox('A write and a comment of the holder move the end and keep the start, and a comment of another session changes nothing')]
    public function testTheHolderRenewsTheClaim(): void
    {
        $claim = self::take(null, self::SESSION, '09:00');

        $written = self::take($claim, self::SESSION, '10:30');
        $commented = IssueClaim::renew($written, self::agent(), self::SESSION, self::at('12:00'));

        self::assertSame(['09:00', '12:30'], [$written->since->format('H:i'), $written->until->format('H:i')]);
        self::assertSame(['09:00', '14:00'], [$commented?->since->format('H:i'), $commented?->until->format('H:i')]);
        self::assertSame($commented, IssueClaim::renew($commented, self::agent(), 'billing-77c0', self::at('12:10')));
    }

    private static function take(?IssueClaim $claim, string $session, string $time): IssueClaim
    {
        return IssueClaim::take($claim, new IssueKey('BILL', 12), self::agent(), $session, self::at($time));
    }

    private static function refusal(IssueClaim $claim, Actor $agent, string $session): InvalidInput
    {
        try {
            IssueClaim::take($claim, new IssueKey('BILL', 12), $agent, $session, self::at('10:00'));
        } catch (InvalidInput $refusal) {
            return $refusal;
        }

        self::fail('The write passed.');
    }

    private static function agent(): Actor
    {
        return Actor::agent(self::$owner, 'claude-code laptop');
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable("2026-11-25 {$time}:00", new \DateTimeZone('UTC'));
    }
}
