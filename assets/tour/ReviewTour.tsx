import { useCallback, useEffect, useMemo, useReducer, useState } from 'react';
import { type FloatingOptions, Joyride, type Options, type Step, type Styles } from 'react-joyride';
import { useLocation, useMatch, useNavigation, useRouteLoaderData } from 'react-router';
import { type KnownTour, TOURS } from '../api/types/common';
import type { ReviewData } from '../routes/reviews/reviewData';
import { codeBrowserSteps, codeRoot, fileSteps, type TourStart, type TourStep, tourStart } from './tourSteps';
import { type TourEnd, TourEndContext, type TourStepData, TourTooltip } from './TourTooltip';
import { useActiveToken, useTours } from './useTours';

const START_POLL_MS = 100;

/**
 * The window scrolls the code browser (D55), so no step scrolls it. Only the tooltip and Escape end a part. The overlay
 * colour is a Primer token, which the portal inside the themed root inherits.
 */
const OPTIONS = {
    skipBeacon: true,
    skipScroll: true,
    dismissKeyAction: false,
    overlayClickAction: false,
    overlayColor: 'var(--overlay-backdrop-bgColor)',
    spotlightPadding: 4,
} satisfies Partial<Options>;

/** `BaseStyles` of the app, inside the Primer theme root. Primer puts its own portals there too. */
const PORTAL = '[data-portal-root]';

const FLOATING_OPTIONS = { hideArrow: true } satisfies Partial<FloatingOptions>;

const STYLES = { floater: { filter: 'none' } } satisfies Partial<Styles>;

/**
 * The review tour, one part at a time, until the account has completed it (D78): `code_browser` on a directory, then
 * `file` on the code of a file of an open review.
 */
export function ReviewTour() {
    const { completed, save } = useTours();
    // A part ends once per visit, also when its write fails.
    const [ended, setEnded] = useState<readonly KnownTour[]>([]);
    const done = useMemo(() => TOURS.filter((tour) => completed.includes(tour) || ended.includes(tour)), [completed, ended]);
    const open = 'open' === useRouteLoaderData<ReviewData>('review')?.review.state;
    const root = useMatch('/reviews/:reviewId');
    const subdirectory = useMatch('/reviews/:reviewId/tree/*');
    const directory = null !== root || null !== subdirectory;
    const file = null !== useMatch('/reviews/:reviewId/files/*');
    const part: KnownTour | null = directory && !done.includes('code_browser') ? 'code_browser' : file && open && !done.includes('file') ? 'file' : null;
    const end = useCallback(
        (scope: TourEnd) => {
            const next = TOURS.filter((tour) => 'tour' === scope || tour === part || done.includes(tour));
            setEnded(next);
            save(next);
        },
        [part, done, save],
    );

    return null === part ? null : <TourPart key={part} part={part} onEnd={end} />;
}

/**
 * Starts once {@link tourStart} finds the part ready. Escape and a navigation to another path end it.
 */
function TourPart({ part, onEnd }: { part: KnownTour; onEnd(end: TourEnd): void }) {
    const start = useStart(part);
    const activeToken = useActiveToken('code_browser' === part);
    const steps = useMemo(() => (null === start ? null : joyrideSteps('code_browser' === part ? codeBrowserSteps(activeToken) : fileSteps(start.line ?? 1))), [start, part, activeToken]);
    const started = null !== steps;
    const navigation = useNavigation();
    const { pathname } = useLocation();
    const leaving = started && undefined !== navigation.location && navigation.location.pathname !== pathname;

    useEffect(() => {
        if (leaving) {
            onEnd('part');
        }
    }, [leaving, onEnd]);

    useFollowCodeRows('file' === part && started);

    useEffect(() => {
        if (!started) {
            return undefined;
        }
        const close = (event: KeyboardEvent): void => {
            if ('Escape' === event.key) {
                onEnd('part');
            }
        };
        document.addEventListener('keydown', close);

        return () => document.removeEventListener('keydown', close);
    }, [started, onEnd]);

    return null === steps ? null : (
        <TourEndContext value={onEnd}>
            <Joyride
                run
                continuous
                steps={steps}
                tooltipComponent={TourTooltip}
                loaderComponent={null}
                portalElement={PORTAL}
                options={OPTIONS}
                floatingOptions={FLOATING_OPTIONS}
                styles={STYLES}
            />
        </TourEndContext>
    );
}

/**
 * The code view replaces its rows, for example when the highlighting arrives or a comment box opens. Joyride anchors
 * the tooltip to the element that its target gave at the last render, so a change renders the tour again.
 */
function useFollowCodeRows(enabled: boolean): void {
    const [, renderAgain] = useReducer((renders: number) => renders + 1, 0);

    useEffect(() => {
        const root = enabled ? codeRoot() : null;
        if (null === root) {
            return undefined;
        }
        let frame = 0;
        const observer = new MutationObserver(() => {
            frame ||= requestAnimationFrame(() => {
                frame = 0;
                renderAgain();
            });
        });
        observer.observe(root, { childList: true, subtree: true });

        return () => {
            observer.disconnect();
            cancelAnimationFrame(frame);
        };
    }, [enabled]);
}

/** Null until {@link tourStart} finds the part ready. The code view renders its rows seconds after the route. */
function useStart(part: KnownTour): TourStart | null {
    const [start, setStart] = useState<TourStart | null>(null);

    useEffect(() => {
        if (null !== start) {
            return undefined;
        }
        const timer = setInterval(() => setStart(tourStart(part)), START_POLL_MS);

        return () => clearInterval(timer);
    }, [part, start]);

    return start;
}

/** The steps whose target exists when the part starts. */
function joyrideSteps(steps: TourStep[]): Step[] {
    return steps
        .filter(({ target }) => null !== target())
        .map(({ title, text, target, placement, settingsLink = false }) => ({ title, content: text, target, placement, data: { settingsLink } satisfies TourStepData }));
}
