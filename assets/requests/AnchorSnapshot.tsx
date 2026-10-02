import type { Anchor } from '../api/types/anchor';
import styles from './Requests.module.css';
import { snapshotRows } from './snapshotRows';

/**
 * The anchored lines as captured at the review commit.
 */
export function AnchorSnapshot({ anchor }: { anchor: Anchor }) {
    if (null === anchor.snapshot) {
        return <p className={styles.muted}>This request is about the whole file.</p>;
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
                            <tr key={row.line} data-line={row.line}>
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
