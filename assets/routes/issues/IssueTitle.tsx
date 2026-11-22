import { Button, Heading } from '@primer/react';
import type { Issue } from '../../api/types/issue';
import titleStyles from '../../requests/RequestTitle.module.css';
import { TitleEditor, useEditButton } from '../../requests/TitleEditor';
import styles from './Issues.module.css';
import { useIssueWrite } from './useIssueWrite';

/** The h1 of the issue page. The editor closes when I7 succeeds and keeps the text when it fails. */
export function IssueTitle({ issue }: { issue: Issue }) {
    const { busy, error, open: editing, setOpen, send } = useIssueWrite();
    const editButton = useEditButton(editing);

    if (editing) {
        return <TitleEditor title={issue.title} busy={busy} error={error} onSave={(title) => send({ intent: 'update', input: { title } })} onClose={() => setOpen(false)} />;
    }

    return (
        <div className={titleStyles.heading}>
            <Heading as="h1" className={titleStyles.text}>
                {issue.title} <span className={styles.key}>{issue.key}</span>
            </Heading>
            <Button ref={editButton} size="small" className={titleStyles.edit} onClick={() => setOpen(true)}>
                Edit
            </Button>
        </div>
    );
}
