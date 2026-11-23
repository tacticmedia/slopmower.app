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
use App\Domain\Issue\CommentLabel;
use App\Domain\Issue\IssueEntryKind;
use App\Domain\Issue\IssueKey;
use App\Domain\Issue\IssueLinkKind;
use App\Domain\Issue\IssueRules;
use App\Domain\Issue\IssueSection;
use App\Domain\Issue\IssueStatus;
use App\Domain\Request\CommentQuestion;
use App\Domain\Request\RequestDecider;
use App\Entity\Issue;
use App\Entity\IssueEntry;
use App\Entity\IssueLink;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The writes of the issue tracker (D116, D117): docs/specs/mcp.md sections 4.22 to 4.27, and I3, I4, and I6 to I8 of
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
     * In any status: no rule of {@see IssueRules} binds a comment. With questions (D124), an agent asks, a user answers
     * the open question comment of $replaces, and the label is `question` or `answer`, whatever $label is.
     *
     * @param IssueEntryId|null     $replaces  A comment of the issue that the new comment replaces (D123)
     * @param list<CommentQuestion> $questions
     *
     * @throws NotFound|InvalidInput
     */
    public function comment(IssueKey $key, string $body, CommentLabel $label, Actor $actor, ?IssueEntryId $replaces = null, array $questions = []): Issue
    {
        return $this->transactional(function (\DateTimeImmutable $now) use ($key, $body, $label, $actor, $replaces, $questions): Issue {
            $issue = $this->issues->issue($key, LockMode::PESSIMISTIC_WRITE);

            if ([] === $questions) {
                self::requireText('body', $body);

                if (CommentLabel::Question === $label || CommentLabel::Answer === $label) {
                    throw InvalidInput::field('label', 'The server sets this label on a comment with questions. Give questions, or another label.');
                }
            }

            $violations = RequestDecider::questionViolations($questions, $actor->kind);

            if ([] !== $violations) {
                throw new InvalidInput($violations);
            }

            $replaced = null === $replaces ? null : $this->replaceable($issue, $replaces, $actor);
            [$label, $questions] = match (true) {
                [] === $questions => [$label, []],
                ActorKind::User === $actor->kind => [CommentLabel::Answer, self::answers($questions, $replaced)],
                default => [CommentLabel::Question, array_map(static fn (CommentQuestion $question): CommentQuestion => new CommentQuestion(trim($question->question), $question->detail), $questions)],
            };
            $this->entityManager->persist(IssueEntry::comment($issue, $actor, $body, $label, $now, $replaced, $questions));
            $issue->touch($now);

            return $issue;
        });
    }

    /**
     * One link per pair: it replaces the link of the pair in either order (D126). Writes no entry.
     *
     * @param IssueLinkKind $kind BlockedBy: $key waits for $targetKey
     *
     * @throws NotFound|InvalidInput
     */
    public function link(IssueKey $key, IssueKey $targetKey, IssueLinkKind $kind, string $note, Actor $actor): Issue
    {
        return $this->transactional(function (\DateTimeImmutable $now) use ($key, $targetKey, $kind, $note, $actor): Issue {
            $keys = [$key->toString() => $key, $targetKey->toString() => $targetKey];
            // In key order, so two links of one pair lock the rows in the same order.
            ksort($keys);
            $locked = array_map(fn (IssueKey $each): Issue => $this->issues->issue($each, LockMode::PESSIMISTIC_WRITE), $keys);
            [$issue, $target] = [$locked[$key->toString()], $locked[$targetKey->toString()]];
            self::requireText('note', $note);

            if ($issue === $target) {
                throw InvalidInput::field('target', 'An issue has no link to itself.');
            }

            $stored = $this->entityManager->createQueryBuilder()
                ->select('link')
                ->from(IssueLink::class, 'link')
                ->where('(link.issue = :issue AND link.target = :target) OR (link.issue = :target AND link.target = :issue)')
                ->setParameter('issue', $issue->getId(), IssueId::class)
                ->setParameter('target', $target->getId(), IssueId::class)
                ->getQuery()
                ->getOneOrNullResult();

            if (null !== $stored) {
                $this->entityManager->remove($stored);
                // The new row can have the key of the removed one, and a flush inserts before it deletes.
                $this->entityManager->flush();
            }

            $this->entityManager->persist(new IssueLink($issue, $target, $kind, $note, $actor, $now));
            $issue->touch($now);
            $target->touch($now);

            return $issue;
        });
    }

    /**
     * Under the row lock of $issue, so no second comment replaces the same one.
     *
     * @throws NotFound     An entry that the caller cannot see
     * @throws InvalidInput
     */
    private function replaceable(Issue $issue, IssueEntryId $id, Actor $actor): IssueEntry
    {
        $entry = $this->entry('entry.id = :id', $id) ?? throw new NotFound();

        return match (true) {
            $entry->getIssue() !== $issue || IssueEntryKind::Comment !== $entry->getKind() => throw InvalidInput::field('replaces', 'Is no comment of this issue.'),
            null !== $this->entry('entry.replaces = :id', $id) => throw InvalidInput::field('replaces', 'This comment is replaced already. Replace the comment that replaced it.'),
            ActorKind::Agent === $actor->kind && ActorKind::User === $entry->getActor()->kind => throw InvalidInput::field('replaces', 'An agent cannot replace a comment of a user.'),
            default => $entry,
        };
    }

    /**
     * The answers of a user as the questions of the open question comment $asked, each with its detail (D124).
     *
     * @param list<CommentQuestion> $answers Each with an answer
     *
     * @return list<CommentQuestion>
     *
     * @throws InvalidInput
     */
    private static function answers(array $answers, ?IssueEntry $asked): array
    {
        $questions = $asked?->getQuestions() ?? [];

        return match (true) {
            null === $asked => throw InvalidInput::field('replaces', 'Answers need the ID of the question comment that they answer.'),
            CommentLabel::Question !== $asked->getLabel() || [] === $questions => throw InvalidInput::field('replaces', 'Is no open question comment.'),
            array_column($questions, 'question') !== array_map(static fn (CommentQuestion $answer): string => trim($answer->question), $answers) => throw InvalidInput::field('questions', 'Must repeat each question of the question comment, in its order, with its answer.'),
            default => array_map(static fn (array $question, CommentQuestion $answer): CommentQuestion => new CommentQuestion($question['question'], $question['detail'], trim($answer->answer ?? '')), $questions, $answers),
        };
    }

    private function entry(string $where, IssueEntryId $id): ?IssueEntry
    {
        return $this->entityManager->createQueryBuilder()
            ->select('entry')
            ->from(IssueEntry::class, 'entry')
            ->where($where)
            ->setParameter('id', $id, IssueEntryId::class)
            ->getQuery()
            ->getOneOrNullResult();
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
