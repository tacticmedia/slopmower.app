import { Button } from '@primer/react';
import type { ReactNode } from 'react';
import type { IssueSection as Section } from '../../api/types/common';
import type { Issue } from '../../api/types/issue';
import { MarkdownBody } from '../../comments/MarkdownBody';
import { ApprovePlan, canApprove, SECTION_TITLES, SectionEditor, useSectionWrite } from './IssueSection';
import styles from './Issues.module.css';

/**
 * The body of the entry of the text that a section has now. "Revise" opens the editor in the place of the text. The
 * timeline keys the entry by its section, so the editor and a refusal of "Approve plan" stay when a newer version
 * becomes this entry.
 *
 * @param line Who wrote the version, and when
 */
export function SectionEntry({ issue, section, line }: { issue: Issue; section: Section; line: ReactNode }) {
    const write = useSectionWrite(issue, section);

    return (
        <>
            <div className={styles.entryHeader}>
                <p className={styles.entryLine}>{line}</p>
                {!write.open && (
                    <Button size="small" aria-label={`Revise ${SECTION_TITLES[section]}`} onClick={() => write.setOpen(true)}>
                        Revise
                    </Button>
                )}
            </div>
            <div className={styles.section} data-testid="issue-section" data-section={section}>
                {write.open ? (
                    <div className={styles.sectionEditor}>
                        <SectionEditor issue={issue} section={section} write={write} />
                    </div>
                ) : (
                    <div className={styles.sectionBody}>
                        <MarkdownBody markdown={issue[section] ?? ''} />
                    </div>
                )}
                {'plan' === section && !write.open && canApprove(issue) && <ApprovePlan planRevision={issue.planRevision} />}
            </div>
        </>
    );
}
