import { ProgressBar } from '@primer/react';
import type { IndexStatus } from '../api/types/review';
import styles from './IndexProgress.module.css';

const count = new Intl.NumberFormat('en-US');

function runningText(index: IndexStatus): string {
    if ('pending' === index.state) {
        return 'Symbol index queued';
    }

    return null === index.filesTotal
        ? 'Symbol index: listing files'
        : `Symbol index: ${count.format(index.filesDone)} of ${count.format(index.filesTotal)} files`;
}

function percentDone(index: IndexStatus): number {
    return null === index.filesTotal || 0 === index.filesTotal ? 0 : Math.floor((index.filesDone * 100) / index.filesTotal);
}

/** The review layout revalidates on `index` live updates, so `index` comes fresh from the review loader. */
export function IndexProgress({ index }: { index: IndexStatus }) {
    if ('ready' === index.state) {
        return (
            <span className={styles.done} data-testid="index-progress" data-state={index.state}>
                Symbol index ready: {count.format(index.filesTotal ?? 0)} files, {count.format(index.symbolsTotal)} symbols
            </span>
        );
    }

    if ('failed' === index.state) {
        return (
            <span className={styles.failed} data-testid="index-progress" data-state={index.state}>
                Symbol index failed: {index.error ?? 'no reason given'}
            </span>
        );
    }

    const text = runningText(index);

    return (
        <div className={styles.running} data-testid="index-progress" data-state={index.state}>
            <ProgressBar progress={percentDone(index)} aria-label={text} barSize="small" className={styles.bar} />
            <span className={styles.text}>{text}</span>
        </div>
    );
}
