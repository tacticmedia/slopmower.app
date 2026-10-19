import { describe, expect, it } from 'vitest';
import { redirect, UNSAFE_createMemoryHistory as createMemoryHistory, UNSAFE_createRouter as createRouter } from 'react-router';

type Gate = { promise: Promise<void>; open(): void; reached: Promise<void>; reach(): void };

function gate(): Gate {
    let open = (): void => undefined;
    let reach = (): void => undefined;
    const promise = new Promise<void>((resolve) => {
        open = resolve;
    });
    const reached = new Promise<void>((resolve) => {
        reach = resolve;
    });

    return { promise, open, reached, reach };
}

/** The router settles in microtasks, because no loader waits for I/O. */
function settle(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve));
}

describe('FetcherRedirectTest', () => {
    it('A fetcher redirect during a live revalidation ends at its target in the router and in the history (bin/patch-react-router.mjs, D82)', async () => {
        let revalidation: Gate | null = null;
        const review = gate();
        const history = createMemoryHistory({ initialEntries: ['/repositories/1'] });
        const router = createRouter({
            history,
            routes: [
                {
                    id: 'repository',
                    path: '/repositories/:repositoryId',
                    loader: async () => {
                        revalidation?.reach();
                        await revalidation?.promise;

                        return null;
                    },
                    action: () => redirect('/reviews/2'),
                },
                {
                    id: 'review',
                    path: '/reviews/:reviewId',
                    loader: async () => {
                        review.reach();
                        await review.promise;

                        return null;
                    },
                },
            ],
        }).initialize();
        await settle();
        revalidation = gate();

        void router.revalidate();
        await revalidation.reached;
        void router.fetch('start', 'repository', '/repositories/1', { formMethod: 'POST', formData: new FormData() });
        await review.reached;
        revalidation.open();
        await settle();
        review.open();
        await settle();

        expect(router.state.location.pathname).toBe('/reviews/2');
        expect(history.location.pathname).toBe('/reviews/2');
        router.dispose();
    });
});
