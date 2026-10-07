import { isEditableElement } from '@primer/behaviors/utils';
import { CheckIcon, CodeIcon, CommentDiscussionIcon } from '@primer/octicons-react';
import { Banner, Button, Heading, Link, RelativeTime, UnderlineNav } from '@primer/react';
import { KeybindingHint } from '@primer/react/experimental';
import { useEffect, useMemo } from 'react';
import { Outlet, Link as RouterLink, useLoaderData, useMatch, useOutletContext } from 'react-router';
import type { Review } from '../../api/types/review';
import { ariaKeyShortcuts, hintKeys, isPressed, type ModShortcut } from '../../layout/modShortcut';
import { usePreference } from '../../layout/usePreference';
import { AgentPromptSnippet } from '../../review/AgentPromptSnippet';
import { CarryOverDialog } from '../../review/CarryOverDialog';
import { CloseReviewDialog } from '../../review/CloseReviewDialog';
import { DeleteReviewDialog } from '../../review/DeleteReviewDialog';
import { FinishReviewOverlay } from '../../review/FinishReviewOverlay';
import { IndexProgress } from '../../review/IndexProgress';
import { QuickOpen } from '../../review/QuickOpen';
import { ReviewIdCopy } from '../../review/ReviewIdCopy';
import { shortSha } from '../../review/shortSha';
import styles from './ReviewLayout.module.css';
import type { ReviewData } from './reviewData';

/** The outlet context of the review routes. Focus mode applies only while a file is open. */
export type FocusMode = { focus: boolean; setFocus(on: boolean): void };

export function useFocusMode(): FocusMode {
    return useOutletContext<FocusMode>();
}

const FOCUS_SHORTCUT: ModShortcut = { key: 'Enter', shift: true };

export function FocusToggle() {
    const { focus, setFocus } = useFocusMode();

    return (
        <Button
            size="small"
            aria-pressed={focus}
            leadingVisual={focus ? CheckIcon : undefined}
            trailingVisual={<KeybindingHint keys={hintKeys(FOCUS_SHORTCUT)} size="small" />}
            aria-keyshortcuts={ariaKeyShortcuts(FOCUS_SHORTCUT)}
            onClick={() => setFocus(!focus)}
        >
            Focus
        </Button>
    );
}

function ClosedBanner({ review }: { review: Extract<Review, { state: 'closed' }> }) {
    return (
        <Banner
            className={styles.closed}
            variant="warning"
            title="This review is closed and read-only"
            data-testid="review-closed"
            description={
                <>
                    {review.closedBy.name} closed it <RelativeTime datetime={review.closedAt} />.
                    {null !== review.closeNote && <span className={styles.note}>{review.closeNote}</span>}
                </>
            }
        />
    );
}

function ReviewNavigation({ review }: { review: Review }) {
    const onRequests = null !== useMatch('/reviews/:reviewId/requests');

    return (
        <UnderlineNav aria-label="Review" variant="flush">
            <UnderlineNav.Item as={RouterLink} to={`/reviews/${review.id}`} leadingVisual={<CodeIcon />} aria-current={onRequests ? undefined : 'page'}>
                Code
            </UnderlineNav.Item>
            <UnderlineNav.Item
                as={RouterLink}
                to={`/reviews/${review.id}/requests`}
                leadingVisual={<CommentDiscussionIcon />}
                counter={review.counts.total}
                aria-current={onRequests ? 'page' : undefined}
            >
                Requests
            </UnderlineNav.Item>
        </UnderlineNav>
    );
}

export function ReviewLayout() {
    const { review, drafts } = useLoaderData<ReviewData>();
    const [focusPreferred, setFocus] = usePreference('focus');
    const fileOpen = null !== useMatch('/reviews/:reviewId/files/*');
    const focus = focusPreferred && fileOpen;
    const focusMode = useMemo<FocusMode>(() => ({ focus, setFocus }), [focus, setFocus]);

    useEffect(() => {
        if (!focus) {
            return;
        }
        const leave = (event: KeyboardEvent): void => {
            // Comment boxes are forms.
            const inForm = event.target instanceof Element && null !== event.target.closest('form');
            if ('Escape' === event.key && !event.defaultPrevented && !inForm) {
                setFocus(false);
            }
        };
        // On the window, after the menus and dialogs that take Escape on the document.
        window.addEventListener('keydown', leave);

        return () => window.removeEventListener('keydown', leave);
    }, [focus, setFocus]);

    useEffect(() => {
        if (!fileOpen) {
            return;
        }
        const toggle = (event: KeyboardEvent): void => {
            const target = event.composedPath()[0] ?? null;
            // A dialog can belong to the review header, which focus mode hides.
            const inDialog = target instanceof Element && null !== target.closest('[role="dialog"]');
            if (isPressed(FOCUS_SHORTCUT, event) && !isEditableElement(target) && !inDialog) {
                event.preventDefault();
                event.stopPropagation();
                setFocus(!focus);
            }
        };
        // In the capture phase: a tree item takes Enter with any modifier and stops its propagation.
        window.addEventListener('keydown', toggle, true);

        return () => window.removeEventListener('keydown', toggle, true);
    }, [fileOpen, focus, setFocus]);

    return (
        <div className={styles.page} data-focus-mode={focus ? '' : undefined}>
            <header className={styles.header}>
                <div className={styles.titleRow}>
                    <div className={styles.titleText}>
                        <Link as={RouterLink} to={`/repositories/${review.repositoryId}`} className={styles.repository}>
                            {review.repositoryName}
                        </Link>
                        <Heading as="h1">{review.title}</Heading>
                        <span className={styles.meta}>
                            <code>{review.ref}</code> at <code title={review.commitSha}>{shortSha(review.commitSha)}</code>, started by{' '}
                            {review.startedBy.name} <RelativeTime datetime={review.createdAt} />
                        </span>
                    </div>
                    <div className={styles.reviewActions}>
                        <QuickOpen reviewId={review.id} indexState={review.index.state} />
                        {'open' === review.state && drafts.length > 0 && <FinishReviewOverlay draftCount={drafts.length} />}
                        {'open' === review.state && <CarryOverDialog review={review} />}
                        {'open' === review.state && <CloseReviewDialog draftCount={drafts.length} />}
                        <DeleteReviewDialog repositoryId={review.repositoryId} />
                    </div>
                </div>
                <div className={styles.agent}>
                    <ReviewIdCopy reviewId={review.id} />
                    <AgentPromptSnippet reviewId={review.id} />
                </div>
                <IndexProgress index={review.index} />
                <ReviewNavigation review={review} />
            </header>
            {'closed' === review.state && <ClosedBanner review={review} />}
            <Outlet context={focusMode} />
        </div>
    );
}
