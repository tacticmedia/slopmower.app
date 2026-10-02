import { memo, type ReactNode } from 'react';
import type { Actor } from '../api/types/actor';
import { ThreadBox } from './ThreadBox';
import styles from './ThreadList.module.css';
import type { ThreadView } from './threadView';

type Props = {
    threads: readonly ThreadView[];
    caller: Actor;
    /** The comment box, below the threads. */
    children?: ReactNode;
};

export const ThreadList = memo(function ThreadList({ threads, caller, children }: Props) {
    return (
        <div className={styles.list} data-testid="thread-list">
            {threads.map((thread) => (
                <ThreadBox key={thread.key} thread={thread} caller={caller} />
            ))}
            {children}
        </div>
    );
});
