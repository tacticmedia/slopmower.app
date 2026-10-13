import { HubotIcon, KebabHorizontalIcon, PersonIcon } from '@primer/octicons-react';
import { ActionList, ActionMenu, Avatar, IconButton, Label, Link, RelativeTime } from '@primer/react';
import { lazy, type ReactNode, Suspense, useOptimistic, useRef, useTransition } from 'react';
import { Link as RouterLink, useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import type { Actor } from '../api/types/actor';
import { OutdatedLabel } from '../requests/OutdatedLabel';
import { PlanToApproveLabel } from '../requests/PlanToApproveLabel';
import { ProposalEntry } from '../requests/ProposalEntry';
import { PullRequestStateLabel } from '../requests/PullRequestStateLabel';
import { StatusLabel } from '../requests/StatusLabel';
import { CommentBox, type CommentBoxHandle } from './CommentBox';
import type { CommentIntent } from './commentIntent';
import styles from './ThreadBox.module.css';
import { type CommentView, linesText, pendingComment, type ThreadView } from './threadView';

// A separate chunk, so the Markdown libraries stay out of the bundles that import this box.
const MarkdownBody = lazy(() => import('./MarkdownBody').then(({ MarkdownBody }) => ({ default: MarkdownBody })));

/** What a thread entry did, after the author name. */
const CAPTIONS: Record<string, string> = {
    'commit.linked': 'linked a commit',
    'pull_request.linked': 'linked a pull request',
    'pull_request.unlinked': 'unlinked a pull request',
    'request.released': 'unassigned this',
    'request.ready_for_review': 'marked this ready for review',
    'request.wont_do': "closed this as won't do",
    'request.verified': 'resolved this conversation',
    'request.reopened': 'reopened this',
    'request.proposed': 'proposed a solution',
    'request.proposal_approved': 'approved the proposal',
    'request.proposal_rejected': 'requested changes',
    'request.plan_required': 'asked for a plan first',
    'request.plan_waived': 'no longer asks for a plan',
};

type Props = {
    thread: ThreadView;
    /** The author of a reply that the server has not confirmed yet. */
    caller: Actor;
    /** False where the page shows the status and the lines already. */
    showHeader?: boolean;
    /** On the request detail: proposal diffs and decisions. Elsewhere a proposal links to the request detail. */
    detail?: boolean;
};

/**
 * Decision D2. A reply shows at once and disappears again if Q4 fails.
 */
export function ThreadBox({ thread, caller, showHeader = true, detail = false }: Props) {
    const fetcher = useFetcher<ActionResult>();
    const [replying, startReply] = useTransition();
    const [comments, addComment] = useOptimistic(thread.comments, (current: CommentView[], added: CommentView) => [...current, added]);
    const replyBox = useRef<CommentBoxHandle>(null);
    const { requestId } = thread;

    const reply = (body: string): boolean => {
        if (replying || null === requestId) {
            return false;
        }
        const intent: CommentIntent = { intent: 'reply', requestId, input: { body } };
        startReply(async () => {
            addComment(pendingComment('request.commented', body, caller));
            await fetcher.submit(intent, { method: 'post', encType: 'application/json' });
        });

        return true;
    };

    return (
        <article className={styles.thread} data-testid="thread" data-request-id={requestId ?? undefined} aria-busy={null === requestId}>
            {showHeader && (
                <header className={styles.header}>
                    <StatusLabel status={thread.status} />
                    <OutdatedLabel outdated={thread.outdated} />
                    <PlanToApproveLabel pending={thread.proposalPending} />
                    <span className={styles.lines}>{linesText(thread.range)}</span>
                    {null !== thread.symbol && (
                        <code className={styles.symbol} data-testid="thread-symbol">
                            {thread.symbol}
                        </code>
                    )}
                </header>
            )}
            {null !== thread.carriedFrom && (
                <p className={styles.carriedFrom} data-testid="carried-from">
                    Carried from the{' '}
                    <Link as={RouterLink} to={`/requests/${thread.carriedFrom.requestId}`}>
                        source request
                    </Link>
                </p>
            )}
            <ol className={styles.comments}>
                {comments.map((comment) => {
                    const { body, proposal } = comment;

                    return (
                        <ThreadComment key={comment.key} comment={comment} onQuote={thread.replyable && null !== body ? () => replyBox.current?.quote(body) : null}>
                            {null !== proposal && <ProposalEntry proposal={proposal} requestId={requestId} decidable={thread.decidable} detail={detail} />}
                        </ThreadComment>
                    );
                })}
            </ol>
            {thread.replyable && (
                <div className={styles.reply}>
                    <CommentBox ref={replyBox} label="Reply" submitLabel="Reply" onSubmit={reply} error={replying ? null : (fetcher.data?.error ?? null)} busy={replying} />
                </div>
            )}
        </article>
    );
}

function ThreadComment({ comment, onQuote, children }: { comment: CommentView; onQuote: (() => void) | null; children?: ReactNode }) {
    const { author, pullRequestUrl } = comment;
    const caption = CAPTIONS[comment.type];

    return (
        <li className={styles.comment} data-testid="thread-comment" aria-busy={comment.pending}>
            <div className={styles.meta}>
                <AuthorPicture author={author} />
                <strong>{author.name}</strong>
                {'agent' === author.kind && <Label variant="secondary">Agent</Label>}
                {comment.question && <Label variant="attention">Question</Label>}
                {undefined !== caption && <span className={styles.muted}>{caption}</span>}
                {comment.pending ? <span className={styles.muted}>Sending</span> : <RelativeTime datetime={comment.occurredAt} className={styles.muted} />}
                {null !== onQuote && (
                    <ActionMenu>
                        <ActionMenu.Anchor>
                            <IconButton icon={KebabHorizontalIcon} aria-label="Comment actions" variant="invisible" size="small" className={styles.menu} />
                        </ActionMenu.Anchor>
                        <ActionMenu.Overlay>
                            <ActionList>
                                <ActionList.Item onSelect={onQuote}>Quote reply</ActionList.Item>
                            </ActionList>
                        </ActionMenu.Overlay>
                    </ActionMenu>
                )}
            </div>
            {null !== pullRequestUrl && (
                <span className={styles.pullRequest}>
                    <Link href={pullRequestUrl} target="_blank" rel="noreferrer" data-testid="thread-pull-request">
                        {pullRequestUrl}
                    </Link>
                    <PullRequestStateLabel state={comment.pullRequestState} />
                </span>
            )}
            {null !== comment.body && (
                <Suspense fallback={<p className={styles.plain}>{comment.body}</p>}>
                    <MarkdownBody markdown={comment.body} />
                </Suspense>
            )}
            {children}
        </li>
    );
}

function AuthorPicture({ author }: { author: Actor }) {
    if (null !== author.avatarUrl) {
        return <Avatar src={author.avatarUrl} alt="" size={20} />;
    }

    return 'agent' === author.kind ? <HubotIcon size={20} aria-hidden /> : <PersonIcon size={20} aria-hidden />;
}
