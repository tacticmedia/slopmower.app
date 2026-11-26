<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Event\IssueChanged;
use App\Application\Query\IssueQuery;
use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Identity\IssueEntryId;
use App\Domain\Identity\IssueId;
use App\Domain\Identity\ProjectId;
use App\Domain\Issue\ApprovalMode;
use App\Domain\Issue\CommentLabel;
use App\Domain\Issue\IssueCategory;
use App\Domain\Issue\IssueClaim;
use App\Domain\Issue\IssueEntryKind;
use App\Domain\Issue\IssueKey;
use App\Domain\Issue\IssueLinkKind;
use App\Domain\Issue\IssueParty;
use App\Domain\Issue\IssuePartyKind;
use App\Domain\Issue\IssuePriority;
use App\Domain\Issue\IssueRules;
use App\Domain\Issue\IssueSection;
use App\Domain\Issue\IssueState;
use App\Domain\Issue\IssueStatus;
use App\Domain\Issue\WaitingFor;
use App\Domain\Request\CommentQuestion;
use App\Domain\Request\RequestDecider;
use App\Entity\Issue;
use App\Entity\IssueEntry;
use App\Entity\IssueLink;
use App\Entity\Project;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The writes of the issue tracker (D116, D117): docs/specs/mcp.md sections 4.22 to 4.27, and I3, I4, and I6 to I8 of
 * docs/specs/rest-api-issues.md. Each write runs in one transaction under the row lock of its issue, appends its entry,
 * and passes {@see IssueRules} before it commits. A write that appends an entry locks the project row first, because the
 * entry takes the next number of the project (D128). A write of an agent checks and takes the claim of
 * {@see IssueClaim} before the rules (D130). A creation and a move lock the project row, so the ranks of a project change
 * in one write at a time (D137).
 */
final class IssueCommands
{
    private const int REVIEW_NOTE_MIN_CHARACTERS = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IssueQuery $issues,
        private readonly ClockInterface $clock,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * The number and the place come from the project under its row lock. By default the user of $actor requests, and an
     * agent works (D134).
     *
     * @throws NotFound|InvalidInput
     */
    public function create(string $projectKey, string $title, IssueCategory $category, ?string $problem, IssueStatus $status, Actor $actor, ?ApprovalMode $approval = null, ?IssuePartyKind $requester = null, ?IssuePartyKind $assignee = null, ?IssuePriority $priority = null): Issue
    {
        return $this->transactional(function (\DateTimeImmutable $now) use ($projectKey, $title, $category, $problem, $status, $actor, $approval, $requester, $assignee, $priority): array {
            $project = $this->issues->project($projectKey, LockMode::PESSIMISTIC_WRITE);
            $issue = new Issue(
                $project,
                $project->takeIssueNumber(),
                $this->newRank($project, $category, $priority ?? IssuePriority::None),
                self::title($title),
                $category,
                $status,
                IssueParty::forCaller($requester ?? IssuePartyKind::User, $actor),
                IssueParty::forCaller($assignee ?? IssuePartyKind::Agent, $actor),
                $actor,
                $now,
            );
            $this->entityManager->persist($issue);
            self::changeApproval($issue, $approval, $actor, $now, isNew: true);

            if (null !== $problem) {
                $this->changeSection($issue, IssueSection::Problem, $problem, $actor, $now);
            }

            $this->entityManager->flush();
            IssueRules::check(null, $this->issues->state($issue), $issue->getApprovalMode(), $actor->kind);

            return [$issue];
        });
    }

    /**
     * Null leaves a field as it is. A value that equals the stored one changes nothing. A category and a party write no
     * entry (D133, D134).
     *
     * @throws NotFound|InvalidInput
     */
    public function update(IssueKey $key, ?string $title, ?string $problem, ?string $plan, ?string $result, ?string $followUps, Actor $actor, ?string $session, ?ApprovalMode $approval = null, ?IssueCategory $category = null, ?IssuePartyKind $requester = null, ?IssuePartyKind $assignee = null): Issue
    {
        $texts = array_filter(['problem' => $problem, 'plan' => $plan, 'result' => $result, 'followUps' => $followUps], static fn (?string $text): bool => null !== $text);

        return $this->write($key, $actor, $session, function (Issue $issue, \DateTimeImmutable $now) use ($title, $texts, $actor, $approval, $category, $requester, $assignee): void {
            if (null === $title && [] === $texts && null === $approval && null === $category && null === $requester && null === $assignee) {
                throw InvalidInput::field('', 'Give at least one field to change.');
            }

            self::changeApproval($issue, $approval, $actor, $now, isNew: false);
            $newRequester = null === $requester ? null : IssueParty::forCaller($requester, $actor);
            $newAssignee = null === $assignee ? null : IssueParty::forCaller($assignee, $actor);

            if (null !== $newRequester && !$newRequester->equals($issue->getRequester())) {
                $issue->setRequester($newRequester, $now);
            }

            if (null !== $newAssignee && !$newAssignee->equals($issue->getAssignee())) {
                $issue->setAssignee($newAssignee, $now);
            }

            if (null !== $category) {
                $issue->setCategory($category);
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
     * Without $waitingFor, a new status takes its default party and the status that the issue has keeps its party. The
     * status that the issue has with another party is a hand-over (D134). A move to wont_do dismisses each open question
     * comment (D124).
     *
     * @throws NotFound|InvalidInput
     */
    public function setStatus(IssueKey $key, IssueStatus $status, ?string $note, ?string $reviewer, Actor $actor, ?string $session, ?WaitingFor $waitingFor = null): Issue
    {
        return $this->write($key, $actor, $session, function (Issue $issue, \DateTimeImmutable $now, IssueState $before) use ($status, $waitingFor, $note, $reviewer, $actor): void {
            $after = $before->withStatus($status, $waitingFor);
            $this->move($issue, $actor, $after->status, $after->waitingFor, $note, $reviewer, $now);

            if ($after->status !== $before->status && IssueStatus::WontDo === $after->status) {
                foreach ($this->openQuestionComments($issue) as $asked) {
                    $this->entityManager->persist(IssueEntry::comment($issue, $actor, "Dismissed: the issue is won't do.", CommentLabel::Dismissal, $now, $asked, self::dismissed($asked)));
                }
            }
        }, setsStatus: true, note: $note, reviewer: $reviewer);
    }

    /**
     * An agent records the review of the plan by another agent, which the server cannot prove. A user approves without
     * a reviewer and a review note, and hands an issue that waits for its requester to the assignee: from draft to todo,
     * which needs a problem (D134).
     *
     * @throws NotFound|InvalidInput
     */
    public function approvePlan(IssueKey $key, IssueEntryId $planRevision, ?string $reviewer, ?string $reviewNote, Actor $actor, ?string $session): Issue
    {
        return $this->write($key, $actor, $session, function (Issue $issue, \DateTimeImmutable $now) use ($planRevision, $reviewer, $reviewNote, $actor): void {
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

            if ($byUser && WaitingFor::Requester === $issue->getWaitingFor()) {
                $hasProblem = '' !== trim($issue->getSection(IssueSection::Problem) ?? '');
                $this->move($issue, $actor, IssueStatus::Draft === $issue->getStatus() && $hasProblem ? IssueStatus::Todo : $issue->getStatus(), WaitingFor::Assignee, null, null, $now);
            }
        });
    }

    /**
     * Without $waitingFor, no rule of {@see IssueRules} and no claim refuses a comment, in any status. With questions
     * (D124), an agent asks, a user answers the open question comment of $replaces, and the label is `question` or
     * `answer`, whatever $label is. With $dismiss, a user dismisses that comment, and the label is `dismissal`. With
     * $waitingFor, a user hands the issue over in its status after the comment, and the content rules then check the
     * issue (D136).
     *
     * @param IssueEntryId|null     $replaces     A comment of the issue that the new comment replaces (D123)
     * @param list<CommentQuestion> $questions
     * @param IssueEntryId|null     $planRevision The newest plan revision, which a `review` comment of a user rejects (D136)
     *
     * @throws NotFound|InvalidInput
     */
    public function comment(IssueKey $key, string $body, CommentLabel $label, Actor $actor, ?string $session, ?IssueEntryId $replaces = null, array $questions = [], bool $dismiss = false, ?WaitingFor $waitingFor = null, ?IssueEntryId $planRevision = null): Issue
    {
        return $this->transactional(function (\DateTimeImmutable $now) use ($key, $body, $label, $actor, $session, $replaces, $questions, $dismiss, $waitingFor, $planRevision): array {
            $this->issues->project($key->projectKey, LockMode::PESSIMISTIC_WRITE);
            $issue = $this->issues->issue($key, LockMode::PESSIMISTIC_WRITE);

            if (ActorKind::Agent === $actor->kind) {
                $issue->claim(IssueClaim::renew($issue->getClaim(), $actor, self::session($session), $now));
            }

            if ($dismiss && ActorKind::User !== $actor->kind) {
                throw InvalidInput::field('dismiss', 'Only a signed-in user dismisses questions.');
            }

            if ($dismiss && [] !== $questions) {
                throw InvalidInput::field('dismiss', 'A dismissal takes no questions.');
            }

            if (null !== $waitingFor && ActorKind::User !== $actor->kind) {
                throw InvalidInput::field('waitingFor', 'Only a signed-in user hands over with a comment. Call set_issue_status.');
            }

            if (null !== $planRevision && ActorKind::User !== $actor->kind) {
                throw InvalidInput::field('planRevision', 'Only a signed-in user requests changes of a plan.');
            }

            if ([] === $questions && !$dismiss) {
                self::requireText('body', $body);

                if (\in_array($label, [CommentLabel::Question, CommentLabel::Answer, CommentLabel::Dismissal], true)) {
                    throw InvalidInput::field('label', 'The server sets this label on a comment with questions or a dismissal. Give questions, or another label.');
                }
            }

            $violations = RequestDecider::questionViolations($questions, $actor->kind);

            if ([] !== $violations) {
                throw new InvalidInput($violations);
            }

            $replaced = null === $replaces ? null : $this->replaceable($issue, $replaces, $actor);
            [$label, $questions] = match (true) {
                $dismiss => [CommentLabel::Dismissal, self::dismissed($replaced)],
                [] === $questions => [$label, []],
                ActorKind::User === $actor->kind => [CommentLabel::Answer, self::answers($questions, $replaced)],
                default => [CommentLabel::Question, array_map(static fn (CommentQuestion $question): CommentQuestion => new CommentQuestion(trim($question->question), $question->detail), $questions)],
            };

            if (null !== $planRevision && CommentLabel::Review !== $label) {
                throw InvalidInput::field('planRevision', 'A request for changes of a plan has the label review.');
            }

            if (null !== $planRevision && $planRevision->toString() !== $this->issues->state($issue)->planRevision) {
                throw InvalidInput::field('planRevision', 'The plan changed after this revision. Read the plan again.');
            }

            $this->entityManager->persist(IssueEntry::comment($issue, $actor, $dismiss && '' === trim($body) ? 'Dismissed.' : $body, $label, $now, $replaced, $questions, $planRevision));
            $issue->touch($now);
            $issue->recordActivity($now);

            if (null !== $waitingFor) {
                $before = $this->issues->state($issue);
                $this->move($issue, $actor, $issue->getStatus(), $waitingFor, null, null, $now);
                $this->entityManager->flush();
                IssueRules::check($before, $this->issues->state($issue), $issue->getApprovalMode(), $actor->kind);
            }

            return [$issue];
        });
    }

    /**
     * I13 (D137): $key takes the place of $targetKey, or with $after the place after it. Writes no entry and sets no
     * updatedAt.
     *
     * @throws NotFound|InvalidInput
     */
    public function rank(IssueKey $key, IssueKey $targetKey, bool $after): Issue
    {
        return $this->transactional(function () use ($key, $targetKey, $after): array {
            $project = $this->issues->project($key->projectKey, LockMode::PESSIMISTIC_WRITE);
            $issue = $this->issues->issue($key);
            $target = $this->issues->issue($targetKey);
            $refusal = match (true) {
                $target === $issue => 'Is the issue itself.',
                $target->getProject() !== $project => 'Is an issue of another project.',
                $target->getCategory() !== $issue->getCategory() => "Is a {$target->getCategory()->value}. A move stays in the category of the issue.",
                default => null,
            };

            if (null !== $refusal) {
                throw InvalidInput::field($after ? 'after' : 'before', $refusal);
            }

            $rank = $target->getRank() + ($after ? 1 : 0);
            $this->makeRoom($project, $rank, $issue);
            $issue->moveTo($rank);

            return [$issue];
        });
    }

    /**
     * The release of a user, I11: no entry, and no updatedAt.
     *
     * @throws NotFound
     */
    public function release(IssueKey $key): Issue
    {
        return $this->transactional(function () use ($key): array {
            $issue = $this->issues->issue($key, LockMode::PESSIMISTIC_WRITE);
            $issue->claim(null);

            return [$issue];
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
        return $this->transactional(function (\DateTimeImmutable $now) use ($key, $targetKey, $kind, $note, $actor): array {
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

            return [$issue, $target];
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
        $questions = self::openQuestions($asked, 'Answers need the ID of the question comment that they answer.');

        return array_column($questions, 'question') === array_map(static fn (CommentQuestion $answer): string => trim($answer->question), $answers)
            ? array_map(static fn (array $question, CommentQuestion $answer): CommentQuestion => new CommentQuestion($question['question'], $question['detail'], trim($answer->answer ?? '')), $questions, $answers)
            : throw InvalidInput::field('questions', 'Must repeat each question of the question comment, in its order, with its answer.');
    }

    /**
     * The questions of the open question comment $asked, each with its detail and without an answer (D124).
     *
     * @return list<CommentQuestion>
     *
     * @throws InvalidInput
     */
    private static function dismissed(?IssueEntry $asked): array
    {
        return array_map(static fn (array $question): CommentQuestion => new CommentQuestion($question['question'], $question['detail']), self::openQuestions($asked, 'A dismissal needs the ID of the question comment that it dismisses.'));
    }

    /**
     * @return list<array{question: string, detail: ?string, answer: ?string}>
     *
     * @throws InvalidInput
     */
    private static function openQuestions(?IssueEntry $asked, string $withoutReplaces): array
    {
        $questions = $asked?->getQuestions() ?? [];

        return match (true) {
            null === $asked => throw InvalidInput::field('replaces', $withoutReplaces),
            CommentLabel::Question !== $asked->getLabel() || [] === $questions => throw InvalidInput::field('replaces', 'Is no open question comment.'),
            default => $questions,
        };
    }

    /**
     * @return list<IssueEntry> The question comments of $issue with questions that no comment replaced, oldest first
     */
    private function openQuestionComments(Issue $issue): array
    {
        /** @var list<IssueEntry> $asked */
        $asked = $this->entityManager->createQueryBuilder()
            ->select('entry')
            ->from(IssueEntry::class, 'entry')
            ->where('entry.issue = :issue')
            ->andWhere('entry.label = :question')
            ->andWhere('NOT EXISTS (SELECT newer.id FROM '.IssueEntry::class.' newer WHERE newer.replaces = entry)')
            ->setParameter('issue', $issue->getId(), IssueId::class)
            ->setParameter('question', CommentLabel::Question)
            ->orderBy('entry.id')
            ->getQuery()
            ->getResult();

        return array_values(array_filter($asked, static fn (IssueEntry $entry): bool => [] !== $entry->getQuestions()));
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
     * A write that {@see IssueRules} checks: the claim of an agent, the change, then the check of the state that it gave.
     *
     * @param \Closure(Issue, \DateTimeImmutable, IssueState): void $change Gets the state before the change
     */
    private function write(IssueKey $key, Actor $actor, ?string $session, \Closure $change, bool $setsStatus = false, ?string $note = null, ?string $reviewer = null): Issue
    {
        return $this->transactional(function (\DateTimeImmutable $now) use ($key, $actor, $session, $change, $setsStatus, $note, $reviewer): array {
            $project = $this->issues->project($key->projectKey, LockMode::PESSIMISTIC_WRITE);
            $issue = $this->issues->issue($key, LockMode::PESSIMISTIC_WRITE);

            if (ActorKind::Agent === $actor->kind) {
                $issue->claim(IssueClaim::take($issue->getClaim(), $issue->getKey(), $actor, self::session($session), $now));
            }

            $before = $this->issues->state($issue);
            $entrySeq = $project->getEntrySeq();
            $change($issue, $now, $before);

            // Each entry takes the next number of the project, so a new number means a new entry: activity (D137).
            if ($project->getEntrySeq() !== $entrySeq) {
                $issue->recordActivity($now);
            }

            // The state reads the plan revision from the stored entries.
            $this->entityManager->flush();
            IssueRules::check($before, $this->issues->state($issue), $issue->getApprovalMode(), $actor->kind, $setsStatus, $note, $reviewer);

            return [$issue];
        });
    }

    /**
     * A status change or a hand-over, with its entry. A move that passes the issue on ends its claim, also a move of a
     * user (D130, D134). The same status and party change nothing.
     */
    private function move(Issue $issue, Actor $actor, IssueStatus $to, ?WaitingFor $waitingFor, ?string $note, ?string $reviewer, \DateTimeImmutable $now): void
    {
        $from = $issue->getStatus();

        if ($to === $from && $waitingFor === $issue->getWaitingFor()) {
            return;
        }

        $this->entityManager->persist(IssueEntry::statusChanged($issue, $actor, $from, $to, $waitingFor, $note, $reviewer, $now));
        $issue->setStatus($to, $waitingFor, $now);

        if (IssueClaim::endsWith($from, $to, $issue->waitsFor()?->kind)) {
            $issue->claim(null);
        }
    }

    /**
     * D129: only in a by_issue project. An agent sets any approval on a new issue and later only user, so it never
     * lowers the approval of a plan that it wrote. Writes no entry, as a new title writes none.
     *
     * @throws InvalidInput
     */
    private static function changeApproval(Issue $issue, ?ApprovalMode $approval, Actor $actor, \DateTimeImmutable $now, bool $isNew): void
    {
        $mode = $issue->getProject()->getApprovalMode();
        $refusal = match (true) {
            null === $approval => null,
            ApprovalMode::ByIssue !== $mode => "The approval mode of the project is {$mode->value}. Only a project in by_issue takes an approval per issue.",
            ApprovalMode::ByIssue === $approval => 'Must be user, agent_review, or self.',
            !$isNew && ActorKind::Agent === $actor->kind && ApprovalMode::User !== $approval && $approval !== $issue->getApprovalMode() => 'An agent changes the approval of an issue only to user.',
            default => null,
        };

        if (null !== $refusal) {
            throw InvalidInput::field('approval', $refusal);
        }

        if (null !== $approval && $approval !== $issue->getApprovalMode()) {
            $issue->setApproval($approval, $now);
        }
    }

    /**
     * The rank of a new issue of $priority among the open issues of $category, with room made for it (D137).
     */
    private function newRank(Project $project, IssueCategory $category, IssuePriority $priority): int
    {
        /** @var list<int> $openRanks */
        $openRanks = $this->entityManager->createQueryBuilder()
            ->select('issue.rank')
            ->from(Issue::class, 'issue')
            ->where('issue.project = :project')
            ->andWhere('issue.category = :category')
            ->andWhere('issue.status NOT IN (:ended)')
            ->setParameter('project', $project->getId(), ProjectId::class)
            ->setParameter('category', $category)
            ->setParameter('ended', IssueStatus::ended())
            ->orderBy('issue.rank')
            ->getQuery()
            ->getSingleColumnResult();
        $rank = $openRanks[$priority->issuesBefore(\count($openRanks))] ?? null;

        if (null === $rank) {
            return 1 + (int) $this->entityManager->createQueryBuilder()
                ->select('MAX(issue.rank)')
                ->from(Issue::class, 'issue')
                ->where('issue.project = :project')
                ->setParameter('project', $project->getId(), ProjectId::class)
                ->getQuery()
                ->getSingleScalarResult();
        }

        $this->makeRoom($project, $rank);

        return $rank;
    }

    /**
     * Raises by 1 each rank of the project from $rank on, except that of $moved, so the order of the others stays.
     */
    private function makeRoom(Project $project, int $rank, ?Issue $moved = null): void
    {
        $query = $this->entityManager
            ->createQuery('UPDATE '.Issue::class.' issue SET issue.rank = issue.rank + 1 WHERE issue.project = :project AND issue.rank >= :rank'.(null === $moved ? '' : ' AND issue.id <> :moved'))
            ->setParameter('project', $project->getId(), ProjectId::class)
            ->setParameter('rank', $rank);

        if (null !== $moved) {
            $query->setParameter('moved', $moved->getId(), IssueId::class);
        }

        $query->execute();
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
     * Refuses an open transaction, so {@see IssueChanged} always follows a real commit. On any exception it rolls back and
     * clears the entity manager, so a refused change stays in no entity.
     *
     * @param \Closure(\DateTimeImmutable): non-empty-list<Issue> $write Gets the time of the write, in the whole seconds of the
     *                                                                   columns. Returns the changed issues, the issue of the
     *                                                                   answer first.
     */
    private function transactional(\Closure $write): Issue
    {
        $connection = $this->entityManager->getConnection();

        if ($connection->isTransactionActive()) {
            throw new \LogicException('An issue write runs only outside a database transaction.');
        }

        $connection->beginTransaction();

        try {
            $changed = $write(new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp()));
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $error) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $this->entityManager->clear();

            throw $error;
        }

        foreach ($changed as $issue) {
            $this->dispatcher->dispatch(new IssueChanged($issue->getTenantId(), $issue->getKey()));
        }

        return $changed[0];
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
     * @throws InvalidInput For a token without its session, which only REST can omit
     */
    private static function session(?string $session): string
    {
        return $session ?? throw InvalidInput::field('session', 'A token names its agent session in each write.');
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
