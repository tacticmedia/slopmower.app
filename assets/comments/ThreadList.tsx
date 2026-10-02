import { memo, type ReactNode } from 'react';
import type { Actor } from '../api/types/actor';
import { DraftBox } from './DraftBox';
import type { DraftView } from './draftView';
import { ThreadBox } from './ThreadBox';
import styles from './ThreadList.module.css';
import type { ThreadView } from './threadView';

type Props = {
    threads: readonly ThreadView[];
    caller: Actor;
    /** The pending comments, below the threads. */
    drafts?: readonly DraftView[];
    draftsEditable?: boolean;
    /** The comment box, below the threads and the pending comments. */
    children?: ReactNode;
};

export const ThreadList = memo(function ThreadList({ threads, caller, drafts = [], draftsEditable = false, children }: Props) {
    return (
        <div className={styles.list} data-testid="thread-list">
            {threads.map((thread) => (
                <ThreadBox key={thread.key} thread={thread} caller={caller} />
            ))}
            {drafts.map((draft) => (
                <DraftBox key={draft.key} draft={draft} editable={draftsEditable} />
            ))}
            {children}
        </div>
    );
});
