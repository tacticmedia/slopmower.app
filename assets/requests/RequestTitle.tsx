import { Button, Heading } from '@primer/react';
import type { Request as ReviewRequest } from '../api/types/request';
import { IssueKeyText } from '../comments/IssueKeyText';
import styles from './RequestTitle.module.css';
import { TitleEditor, useEditButton } from './TitleEditor';
import { useRequestCommand } from './useRequestCommand';

type Props = Pick<ReviewRequest, 'title' | 'summary' | 'allowedActions'>;

/** The h1 of the request detail. The editor closes when Q23 succeeds and keeps the text when it fails. */
export function RequestTitle({ title, summary, allowedActions }: Props) {
    const { busy, error, dialog, send, openDialog, closeDialog } = useRequestCommand<'title'>();
    const editing = 'title' === dialog;
    const editButton = useEditButton(editing);

    if (editing) {
        return <TitleEditor title={title ?? ''} busy={busy} error={error} onSave={(next) => send({ intent: 'set_title', input: { title: next } })} onClose={closeDialog} />;
    }

    return (
        <div className={styles.heading}>
            <Heading as="h1" className={null === title ? `${styles.text} ${styles.untitled}` : styles.text}>
                <IssueKeyText text={summary} />
            </Heading>
            {allowedActions.includes('set_title') && (
                <Button ref={editButton} size="small" className={styles.edit} onClick={(event) => openDialog('title', event)}>
                    {null === title ? 'Add a title' : 'Edit'}
                </Button>
            )}
        </div>
    );
}
