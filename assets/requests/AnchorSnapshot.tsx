import type { Anchor } from '../api/types/anchor';
import styles from './Requests.module.css';
import { snapshotRows } from './snapshotRows';

/**
 * The anchored lines as captured at the review commit, between dimmed context lines. Q3 gives null lines when it
 * cannot rebuild them (D113), and Browser only gives no snapshot (D114).
 */
export function AnchorSnapshot({ anchor }: { anchor: Anchor }) {
    if ('file' === anchor.kind) {
        return <p className={styles.muted}>This request is about the whole file.</p>;
    }
    if (!anchor.snapshot?.lines) {
        return (
            <p className={styles.muted} data-testid="anchor-snapshot">
                Code not available
            </p>
        );
    }

    return (
        <div className={styles.snapshot} data-testid="anchor-snapshot">
            <table className={styles.snapshotTable}>
                <tbody>
                    {snapshotRows(anchor.range.startLine, anchor.snapshot).map((row) =>
                        'gap' === row.kind ? (
                            <tr key="gap">
                                <td colSpan={2} className={styles.snapshotGap}>
                                    {row.hiddenLines} lines are not shown
                                </td>
                            </tr>
                        ) : (
                            <tr key={row.line} data-line={row.line} data-context={'context' === row.kind ? '' : undefined} className={'context' === row.kind ? styles.snapshotContext : undefined}>
                                <td className={styles.lineNumber}>{row.line}</td>
                                <td className={styles.lineText}>{row.text}</td>
                            </tr>
                        ),
                    )}
                </tbody>
            </table>
        </div>
    );
}
