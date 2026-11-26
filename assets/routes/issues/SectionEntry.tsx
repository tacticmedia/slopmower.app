import { CheckIcon } from '@primer/octicons-react';
import { Banner, Button, Spinner, VisuallyHidden } from '@primer/react';
import { lazy, type ReactNode, Suspense, useEffect, useRef, useState } from 'react';
import { useRouteLoaderData } from 'react-router';
import type { Actor } from '../../api/types/actor';
import type { IssueSection as Section } from '../../api/types/common';
import type { Issue, IssueEntry } from '../../api/types/issue';
import type { Me } from '../../api/types/me';
import { MarkdownBody } from '../../comments/MarkdownBody';
import { ApprovePlan, ChangesEditor, canApprove, canRequestChanges, SECTION_TITLES, SectionEditor, useChangesWrite, useSectionWrite } from './IssueSection';
import styles from './Issues.module.css';

// A separate chunk, so the diff library loads with the first diff.
const TextDiff = lazy(() => import('../../code/CommitDiff').then(({ TextDiff }) => ({ default: TextDiff })));

const PLAN_CHANGED = 'The plan changed while this page was open.';

/**
 * The body of the entry of the text that a section has now. "Revise" opens the editor in the place of the text, and
 * "Request changes" its box in the place of the footer. "Show changes" shows the difference to the earlier version in
 * the place of the text. The timeline keys the entry by its section, so the editors, a refusal of "Approve plan", the
 * plan revision that the user read, and "Show changes" stay when a newer version becomes this entry.
 *
 * @param line             Who wrote the version, and when
 * @param author           The actor of the version
 * @param earlier          The version that this version replaced
 * @param changesRequested The footer of a plan revision with a request for changes, in the place of "Approve plan" (D136)
 */
export function SectionEntry({ issue, section, line, author, earlier, changesRequested }: { issue: Issue; section: Section; line: ReactNode; author: Actor; earlier: IssueEntry | undefined; changesRequested: ReactNode }) {
    const write = useSectionWrite(issue, section);
    const changes = useChangesWrite(issue, section);
    const viewer = useRouteLoaderData<Me>('root')?.caller;
    const [readRevision, setReadRevision] = useState(issue.planRevision);
    const [diffShown, setDiffShown] = useState(false);
    const approveButton = useRef<HTMLButtonElement>(null);
    const text = useRef<HTMLDivElement>(null);
    const focusAfterRead = useRef(false);
    // A live reload brings the plan of another actor, which one click must not approve unread.
    const changed = 'plan' === section && readRevision !== issue.planRevision && (author.kind !== viewer?.kind || author.userId !== viewer.userId || author.agentName !== viewer.agentName);

    // "Read the new plan" leaves the page, so the focus goes to the approval, or to the plan without one.
    useEffect(() => {
        if (focusAfterRead.current) {
            focusAfterRead.current = false;
            (approveButton.current ?? text.current)?.focus();
        }
    }, [readRevision]);

    const read = (): void => {
        focusAfterRead.current = true;
        setReadRevision(issue.planRevision);
    };

    const asksForChanges = canRequestChanges(issue, section);
    const requestChanges = asksForChanges && (
        <Button onClick={() => changes.setOpen(true)} disabled={changed}>
            Request changes
        </Button>
    );

    return (
        <>
            <div className={styles.entryHeader}>
                <p className={styles.entryLine}>{line}</p>
                {!write.open && (
                    <span className={styles.actions}>
                        {undefined !== earlier && (
                            <Button size="small" aria-label={`Show changes of the ${SECTION_TITLES[section].toLowerCase()}`} aria-pressed={diffShown} leadingVisual={diffShown ? CheckIcon : undefined} onClick={() => setDiffShown(!diffShown)}>
                                Show changes
                            </Button>
                        )}
                        <Button size="small" aria-label={`Revise ${SECTION_TITLES[section]}`} onClick={() => write.setOpen(true)}>
                            Revise
                        </Button>
                    </span>
                )}
            </div>
            {'plan' === section && <VisuallyHidden role="status">{changed ? PLAN_CHANGED : ''}</VisuallyHidden>}
            {changed && (
                <Banner
                    variant="warning"
                    title={PLAN_CHANGED}
                    description="Read it before you approve it. The version before it is the replaced entry above."
                    primaryAction={<Banner.PrimaryAction onClick={read}>Read the new plan</Banner.PrimaryAction>}
                    secondaryAction={
                        undefined !== earlier && (
                            <Banner.SecondaryAction
                                onClick={() => {
                                    read();
                                    setDiffShown(true);
                                }}
                            >
                                Show changes
                            </Banner.SecondaryAction>
                        )
                    }
                    data-testid="plan-changed"
                />
            )}
            <div className={styles.section} data-testid="issue-section" data-section={section}>
                {write.open ? (
                    <div className={styles.sectionEditor}>
                        <SectionEditor issue={issue} section={section} write={write} />
                    </div>
                ) : (
                    <div ref={text} tabIndex={-1} className={styles.sectionBody}>
                        {diffShown && undefined !== earlier ? (
                            <Suspense
                                fallback={
                                    <p className={styles.muted}>
                                        <Spinner size="small" srText={null} /> Loading the diff
                                    </p>
                                }
                            >
                                <TextDiff earlier={earlier.body ?? ''} current={issue[section] ?? ''} cacheKey={earlier.id} />
                            </Suspense>
                        ) : (
                            <MarkdownBody markdown={issue[section] ?? ''} />
                        )}
                    </div>
                )}
                {write.open ? null : asksForChanges && changes.open ? (
                    <div className={styles.sectionEditor}>
                        <ChangesEditor issue={issue} section={section} write={changes} disabled={changed} />
                    </div>
                ) : 'plan' === section && null !== changesRequested ? (
                    changesRequested
                ) : 'plan' === section && canApprove(issue) ? (
                    <ApprovePlan ref={approveButton} planRevision={issue.planRevision} disabled={changed}>
                        {requestChanges}
                    </ApprovePlan>
                ) : (
                    requestChanges && <div className={styles.sectionFooter}>{requestChanges}</div>
                )}
            </div>
        </>
    );
}
