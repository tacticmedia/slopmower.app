<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Query\IssueQuery;
use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Identity\IssueEntryId;
use App\Domain\Identity\IssueId;
use App\Domain\Issue\IssueKey;
use App\Domain\Issue\IssueRules;
use App\Domain\Issue\IssueSection;
use App\Domain\Issue\IssueStatus;
use App\Domain\Request\RequestDecider;
use App\Entity\Issue;
use App\Entity\IssueEntry;
use App\Entity\IssueLink;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The writes of the issue tracker (D116, D117): docs/specs/mcp.md sections 4.22 to 4.27, and I3 and I4 of
 * docs/specs/rest-api-issues.md. Each write runs in one transaction under the row lock of its issue, appends its entry,
 * and passes {@see IssueRules} before it commits.
 */
final class IssueCommands
{
    private const int REVIEW_NOTE_MIN_CHARACTERS = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IssueQuery $issues,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The number comes from the project under its row lock.
     *
     * @throws NotFound|InvalidInput
     */
    public function create(string $projectKey, string $title, ?string $problem, IssueStatus $status, Actor $actor): Issue
    {
        return $this->transactional(function (\DateTimeImmutable $now) use ($projectKey, $title, $problem, $status, $actor): Issue {
            $project = $this->issues->project($projectKey, LockMode::PESSIMISTIC_WRITE);
            $issue = new Issue($project, $project->takeIssueNumber(), self::title($title), $status, $actor, $now);
            $this->entityManager->persist($issue);

            if (null !== $problem) {
                $this->changeSection($issue, IssueSection::Problem, $problem, $actor, $now);
            }

            $this->entityManager->flush();
            IssueRules::check(null, $this->issues->state($issue), $project->getApprovalMode(), $actor->kind);

            return $issue;
        });
    }

    /**
     * Null leaves a field as it is. A value that equals the stored one changes nothing.
     *
     * @throws NotFound|InvalidInput
     */
    public function update(IssueKey $key, ?string $title, ?string $problem, ?string $plan, ?string $result, ?string $followUps, Actor $actor): Issue
    {
        $texts = array_filter(['problem' => $problem, 'plan' => $plan, 'result' => $result, 'followUps' => $followUps], static fn (?string $text): bool => null !== $text);

        return $this->write($key, $actor, function (Issue $issue, \DateTimeImmutable $now) use ($title, $texts, $actor): void {
            if (null === $title && [] === $texts) {
                throw InvalidInput::field('', 'Give at least one field to change.');
            }

            $newTitle = null === $title ? null : self::title($title);

            if (null !== $newTitle && $newTitle !== $issue->getTitle()) {
                $issue->rename($newTitle, $now);
            }

            foreach ($texts as $section => $text) {
                $this->changeSection($issue, IssueSection::from($section), $text, $actor, $now);
            }
        });
    }

    /**
     * @throws NotFound|InvalidInput
     */
    public function setStatus(IssueKey $key, IssueStatus $status, ?string $note, ?string $reviewer, Actor $actor): Issue
    {
        return $this->write($key, $actor, function (Issue $issue, \DateTimeImmutable $now) use ($status, $note, $reviewer, $actor): void {
            if ($status !== $issue->getStatus()) {
                $this->entityManager->persist(IssueEntry::statusChanged($issue, $actor, $issue->getStatus(), $status, $note, $reviewer, $now));
                $issue->setStatus($status, $now);
            }
        }, setsStatus: true, note: $note, reviewer: $reviewer);
    }

    /**
     * An agent records the review of the plan by another agent, which the server cannot prove. A user approves without
     * a reviewer and a review note, and that returns an issue in awaiting_user_input to todo, which needs a problem.
     *
     * @throws NotFound|InvalidInput
     */
    public function approvePlan(IssueKey $key, IssueEntryId $planRevision, ?string $reviewer, ?string $reviewNote, Actor $actor): Issue
    {
        return $this->write($key, $actor, function (Issue $issue, \DateTimeImmutable $now) use ($planRevision, $reviewer, $reviewNote, $actor): void {
            $byUser = ActorKind::User === $actor->kind;

            if (!$byUser) {
                self::requireText('reviewer', $reviewer ?? '');

                if (mb_strlen(trim($reviewNote ?? ''), 'UTF-8') < self::REVIEW_NOTE_MIN_CHARACTERS) {
                    throw InvalidInput::field('reviewNote', 'Must have at least 20 characters after trimming.');
                }
            }

            $approval = IssueEntry::planApproved($issue, $actor, $reviewer, $reviewNote, $planRevision, $now);
            $this->entityManager->persist($approval);
            $issue->approvePlan($approval);

            if ($byUser && IssueStatus::AwaitingUserInput === $issue->getStatus() && '' !== trim($issue->getSection(IssueSection::Problem) ?? '')) {
                $this->entityManager->persist(IssueEntry::statusChanged($issue, $actor, IssueStatus::AwaitingUserInput, IssueStatus::Todo, null, null, $now));
                $issue->setStatus(IssueStatus::Todo, $now);
            }
        });
    }

    /**
     * In any status: no rule of {@see IssueRules} binds a comment.
     *
     * @throws NotFound|InvalidInput
     */
    public function comment(IssueKey $key, string $body, Actor $actor): Issue
    {
        return $this->transactional(function (\DateTimeImmutable $now) use ($key, $body, $actor): Issue {
            $issue = $this->issues->issue($key, LockMode::PESSIMISTIC_WRITE);
            self::requireText('body', $body);
            $this->entityManager->persist(IssueEntry::comment($issue, $actor, $body, $now));
            $issue->touch($now);

            return $issue;
        });
    }

    /**
     * One link per pair, in either order. Writes no entry.
     *
     * @throws NotFound|InvalidInput
     */
    public function link(IssueKey $key, IssueKey $targetKey, string $note, Actor $actor): Issue
    {
        return $this->transactional(function (\DateTimeImmutable $now) use ($key, $targetKey, $note, $actor): Issue {
            $keys = [$key->toString() => $key, $targetKey->toString() => $targetKey];
            // In key order, so two links of one pair lock the rows in the same order.
            ksort($keys);
            $locked = array_map(fn (IssueKey $each): Issue => $this->issues->issue($each, LockMode::PESSIMISTIC_WRITE), $keys);
            [$issue, $target] = [$locked[$key->toString()], $locked[$targetKey->toString()]];
            self::requireText('note', $note);

            if ($issue === $target) {
                throw InvalidInput::field('target', 'An issue has no link to itself.');
            }

            $linked = (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(link.tenantId)')
                ->from(IssueLink::class, 'link')
                ->where('(link.issue = :issue AND link.target = :target) OR (link.issue = :target AND link.target = :issue)')
                ->setParameter('issue', $issue->getId(), IssueId::class)
                ->setParameter('target', $target->getId(), IssueId::class)
                ->getQuery()
                ->getSingleScalarResult();

            if ($linked > 0) {
                throw InvalidInput::field('target', "{$key} and {$targetKey} have a link already.");
            }

            $this->entityManager->persist(new IssueLink($issue, $target, $note, $actor, $now));
            $issue->touch($now);
            $target->touch($now);

            return $issue;
        });
    }

    /**
     * A write that {@see IssueRules} checks: the change, then the check of the state that it gave.
     *
     * @param \Closure(Issue, \DateTimeImmutable): void $change
     */
    private function write(IssueKey $key, Actor $actor, \Closure $change, bool $setsStatus = false, ?string $note = null, ?string $reviewer = null): Issue
    {
        return $this->transactional(function (\DateTimeImmutable $now) use ($key, $actor, $change, $setsStatus, $note, $reviewer): Issue {
            $issue = $this->issues->issue($key, LockMode::PESSIMISTIC_WRITE);
            $before = $this->issues->state($issue);
            $change($issue, $now);
            // The state reads the plan revision from the stored entries.
            $this->entityManager->flush();
            IssueRules::check($before, $this->issues->state($issue), $issue->getProject()->getApprovalMode(), $actor->kind, $setsStatus, $note, $reviewer);

            return $issue;
        });
    }

    /**
     * A section with new text gets its section_changed entry.
     */
    private function changeSection(Issue $issue, IssueSection $section, string $text, Actor $actor, \DateTimeImmutable $now): void
    {
        if ($text !== $issue->getSection($section)) {
            $issue->changeSection($section, $text, $now);
            $this->entityManager->persist(IssueEntry::sectionChanged($issue, $actor, $section, $text, $now));
        }
    }

    /**
     * On any exception it rolls back and clears the entity manager, so a refused change stays in no entity.
     *
     * @template T
     *
     * @param \Closure(\DateTimeImmutable): T $write Gets the time of the write, with the second precision of the columns
     *
     * @return T
     */
    private function transactional(\Closure $write): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $result = $write(new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp()));
            $this->entityManager->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $error) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $this->entityManager->clear();

            throw $error;
        }
    }

    /**
     * The title rules of docs/specs/README.md section 2.2, which a request title shares.
     *
     * @throws InvalidInput
     */
    private static function title(string $title): string
    {
        $violations = RequestDecider::titleViolations($title);

        return [] === $violations ? trim($title) : throw new InvalidInput($violations);
    }

    /**
     * @throws InvalidInput
     */
    private static function requireText(string $field, string $text): void
    {
        if ('' === trim($text)) {
            throw InvalidInput::field($field, 'Must contain a non-whitespace character.');
        }
    }
}
