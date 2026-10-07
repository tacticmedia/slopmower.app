import { RelativeTime, VisuallyHidden } from '@primer/react';
import { type RefObject, useEffect, useMemo, useRef, useState } from 'react';
import type { Blame, BlameCommit } from '../api/types/blame';
import type { LineGeometry } from '../code/CodeFile';
import styles from './BlameColumn.module.css';
import { blameAgeScale } from './blameAge';

type Props = { blame: Blame; geometry: LineGeometry | null };

/** The column renders the blocks within this distance above and below the window. */
const OVERSCAN_PX = 2000;

/**
 * GitHub's blame column: one block per range, beside its lines. The rows of the code have one height, so a block sits
 * at the position of its first line and is as high as its lines.
 */
export function BlameColumn({ blame, geometry }: Props) {
    const column = useRef<HTMLDivElement>(null);
    const commits = useMemo(() => new Map(blame.commits.map((commit) => [commit.sha, commit])), [blame.commits]);
    const now = useMemo(() => new Date(), []);
    const visible = useVisibleSpan(column);

    return (
        <div ref={column} className={styles.column} data-testid="blame-column">
            {null !== geometry &&
                blame.ranges.map(({ startLine, endLine, commitSha }) => {
                    const topPx = geometry.firstLineTopPx + (startLine - 1) * geometry.lineHeightPx;
                    const heightPx = (endLine - startLine + 1) * geometry.lineHeightPx;
                    const commit = commits.get(commitSha);

                    return undefined === commit || topPx + heightPx < visible.topPx || topPx > visible.bottomPx ? null : (
                        <BlameBlock key={startLine} commit={commit} startLine={startLine} endLine={endLine} topPx={topPx} heightPx={heightPx} now={now} />
                    );
                })}
        </div>
    );
}

type BlockProps = { commit: BlameCommit; startLine: number; endLine: number; topPx: number; heightPx: number; now: Date };

function BlameBlock({ commit, startLine, endLine, topPx, heightPx, now }: BlockProps) {
    const scale = blameAgeScale(commit.authoredAt, now);
    const lines = startLine === endLine ? `Line ${startLine}` : `Lines ${startLine} to ${endLine}`;

    return (
        <div
            className={styles.block}
            style={{ top: topPx, height: heightPx }}
            data-testid="blame-block"
            data-lines={`${startLine}-${endLine}`}
            data-age-scale={scale}
            title={`${commit.summary}\n${commit.authorName}, ${new Date(commit.authoredAt).toLocaleString()}\n${commit.sha.slice(0, 7)}`}
        >
            <span className={styles.age} style={{ backgroundColor: `var(--display-orange-scale-${scale})` }} aria-hidden="true" />
            <VisuallyHidden>{lines}:</VisuallyHidden>
            <RelativeTime className={styles.date} datetime={commit.authoredAt} />
            <span className={styles.author}>{commit.authorName}</span>
            <span className={styles.summary}>{commit.summary}</span>
        </div>
    );
}

/** The part of the column within {@link OVERSCAN_PX} of the window, in column coordinates, after each scroll. */
function useVisibleSpan(column: RefObject<HTMLDivElement | null>): { topPx: number; bottomPx: number } {
    const [span, setSpan] = useState({ topPx: 0, bottomPx: OVERSCAN_PX });

    useEffect(() => {
        let frame = 0;
        const measure = (): void => {
            frame = 0;
            const top = column.current?.getBoundingClientRect().top ?? 0;
            const topPx = Math.max(-top - OVERSCAN_PX, 0);
            const bottomPx = -top + window.innerHeight + OVERSCAN_PX;
            // Half the overscan keeps the blocks in place while a scroll moves less.
            setSpan((previous) => (Math.abs(previous.topPx - topPx) < OVERSCAN_PX / 2 && Math.abs(previous.bottomPx - bottomPx) < OVERSCAN_PX / 2 ? previous : { topPx, bottomPx }));
        };
        const schedule = (): void => {
            frame ||= requestAnimationFrame(measure);
        };
        measure();
        window.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('resize', schedule, { passive: true });

        return () => {
            window.removeEventListener('scroll', schedule);
            window.removeEventListener('resize', schedule);
            cancelAnimationFrame(frame);
        };
    }, [column]);

    return span;
}
