import { ArrowUpIcon } from '@primer/octicons-react';
import { Button } from '@primer/react';
import { type ReactNode, type RefObject, useEffect, useRef, useState } from 'react';
import { CopyButton } from '../layout/CopyButton';
import { useStuck } from '../layout/useStuck';
import { PlanToApproveLabel } from '../requests/PlanToApproveLabel';
import { SuggestionLabel } from '../requests/SuggestionLabel';
import { ExpandTreeButton } from '../routes/reviews/CodeLayout';
import { Breadcrumbs } from './Breadcrumbs';
import styles from './FileHeader.module.css';

type Props = {
    reviewId: string;
    repositoryName: string;
    /** `""` is the root. */
    path: string;
    directory?: boolean;
    /** A thread of the file has a pending proposal. */
    planToApprove?: boolean;
    /** A thread of the file is a pending suggestion. */
    suggestion?: boolean;
    /** The left part of the toolbar, for example the line count. */
    summary?: ReactNode;
    /** The file commands, at the right of the toolbar. Without a summary and commands, there is no toolbar. */
    children?: ReactNode;
    /** Below the toolbar while the header is stuck. */
    stuckBelow?: ReactNode;
    /** A row of the toolbar below the commands, which sticks with them. */
    toolbarBelow?: ReactNode;
};

/** The height of the stuck row, which shows only while the header is stuck. */
const STUCK_ROW_PX = 44;

/**
 * GitHub's file header: the breadcrumbs scroll away with the page, and the toolbar sticks to the window top. Once it
 * sticks, a row with the breadcrumbs and "Top" shows above it. The sticky part has no height and an absolutely
 * positioned box, so a change of its height never moves the code. The element itself has `display: contents`, so the
 * sticky part sticks for the whole height of the file section.
 */
export function FileHeader({ reviewId, repositoryName, path, directory = false, planToApprove = false, suggestion = false, summary, children, stuckBelow, toolbarBelow }: Props) {
    const sentinel = useRef<HTMLDivElement>(null);
    const toolbar = useRef<HTMLDivElement>(null);
    const stuck = useStuck(sentinel);
    const toolbarPx = useHeight(toolbar);
    const hasToolbar = undefined !== summary || undefined !== children;
    const crumbs = { reviewId, repositoryName, path, directory };

    // The part of the window that the stuck header covers, for the scrolls of the page and of the code view. Without
    // `stuckBelow`: the scope lines follow the first line below this part.
    useEffect(() => {
        document.documentElement.style.scrollPaddingTop = `${toolbarPx + STUCK_ROW_PX}px`;
    }, [toolbarPx]);
    useEffect(
        () => () => {
            document.documentElement.style.removeProperty('scroll-padding-top');
        },
        [],
    );

    return (
        <header className={styles.header} data-testid="file-header">
            <div className={styles.pathRow}>
                <ExpandTreeButton />
                <Breadcrumbs {...crumbs} heading />
                <PlanToApproveLabel pending={planToApprove} />
                <SuggestionLabel pending={suggestion} />
                {'' !== path && <CopyButton text={path} label="Copy path" />}
            </div>
            <div ref={sentinel} className={styles.sentinel} />
            <div className={styles.sticky}>
                <div className={styles.box}>
                    {stuck && (
                        <div className={styles.stuckRow} data-testid="stuck-header">
                            <ExpandTreeButton />
                            <Breadcrumbs {...crumbs} heading={false} />
                            {'' !== path && <CopyButton text={path} label="Copy path" />}
                            <Button className={styles.top} size="small" variant="invisible" leadingVisual={ArrowUpIcon} onClick={() => window.scrollTo({ top: 0 })}>
                                Top
                            </Button>
                        </div>
                    )}
                    {hasToolbar && (
                        <div ref={toolbar} className={styles.toolbar} data-testid="file-toolbar">
                            <div className={styles.toolbarRow}>
                                <div className={styles.summary}>{summary}</div>
                                <div className={styles.actions}>{children}</div>
                            </div>
                            {toolbarBelow}
                        </div>
                    )}
                    {stuck && stuckBelow}
                </div>
            </div>
            <div style={{ height: toolbarPx }} />
        </header>
    );
}

function useHeight(element: RefObject<HTMLElement | null>): number {
    const [heightPx, setHeightPx] = useState(0);

    useEffect(() => {
        const current = element.current;
        if (null === current) {
            setHeightPx(0);

            return;
        }
        const observer = new ResizeObserver(([entry]) => setHeightPx(entry?.borderBoxSize[0]?.blockSize ?? current.offsetHeight));
        observer.observe(current);

        return () => observer.disconnect();
    }, [element]);

    return heightPx;
}
