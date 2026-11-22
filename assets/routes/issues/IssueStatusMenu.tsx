import { ActionList, ActionMenu } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { useRef } from 'react';
import { ISSUE_STATUSES, type IssueStatus } from '../../api/types/common';
import type { Issue } from '../../api/types/issue';
import { NoteDialog } from '../../requests/NoteDialog';
import { ISSUE_STATUS_TEXT } from './IssueStatusLabel';
import styles from './Issues.module.css';
import { useIssueWrite } from './useIssueWrite';

/** What a status means, and the section that the server asks for. */
const MEANINGS: Record<IssueStatus, string> = {
    draft: 'The problem is not complete.',
    backlog: 'The problem is complete. The work waits.',
    todo: 'Ready for work.',
    in_progress: 'The work runs. Needs a plan.',
    awaiting_user_input: 'The agent waits for your decision.',
    in_review: 'The work is done. Needs a result.',
    done: 'Delivered. The issue ends.',
    wont_do: 'Not done. The issue ends, with your note.',
};

/**
 * I8 for every status except the one that the issue has (D122). The server holds the rules, so no status is hidden or
 * disabled, and a refusal shows its message. `wont_do` asks for its note first.
 */
export function IssueStatusMenu({ issue }: { issue: Issue }) {
    const { busy, error, open: noteOpen, setOpen: setNoteOpen, send } = useIssueWrite();
    const anchor = useRef<HTMLButtonElement>(null);
    const noteKey = `issue:${issue.key}:wont-do`;
    const move = (status: IssueStatus): void => {
        if ('wont_do' === status) {
            setNoteOpen(true);
        } else {
            send({ intent: 'set_status', input: { status } });
        }
    };

    return (
        <>
            <ActionMenu anchorRef={anchor}>
                <ActionMenu.Button size="small" loading={busy && !noteOpen}>
                    Set status
                </ActionMenu.Button>
                <ActionMenu.Overlay width="medium">
                    <ActionList>
                        {ISSUE_STATUSES.filter((status) => status !== issue.status).map((status) => (
                            <ActionList.Item key={status} onSelect={() => move(status)}>
                                {ISSUE_STATUS_TEXT[status]}
                                <ActionList.Description variant="block">{MEANINGS[status]}</ActionList.Description>
                            </ActionList.Item>
                        ))}
                    </ActionList>
                </ActionMenu.Overlay>
            </ActionMenu>
            {null !== error && !noteOpen && (
                <InlineMessage variant="critical" role="alert" className={styles.statusError} data-testid="status-error">
                    {error}
                </InlineMessage>
            )}
            {noteOpen && (
                <NoteDialog
                    title="Won't do"
                    subtitle="The issue ends without the change. The note says why."
                    fieldLabel="Note"
                    rule="required"
                    submitLabel="Set won't do"
                    buttonType="danger"
                    draftKey={noteKey}
                    busy={busy}
                    error={error}
                    onSubmit={(note) => send({ intent: 'set_status', input: { status: 'wont_do', note }, draftKey: noteKey })}
                    onClose={() => setNoteOpen(false)}
                    returnFocusRef={anchor}
                />
            )}
        </>
    );
}
