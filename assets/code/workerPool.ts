import type { WorkerInitializationRenderOptions, WorkerPoolOptions } from '@pierre/diffs/worker';
import diffsWorkerUrl from '@pierre/diffs/worker/worker.js?worker&url';

export const THEME = { light: 'github-light', dark: 'github-dark' } as const;

/** One pool serves the page. The library builds it from the options of the first provider that mounts. */
export const POOL_OPTIONS: WorkerPoolOptions = {
    workerFactory: () => new Worker(diffsWorkerUrl, { type: 'module' }),
    poolSize: 2,
};

// No `langs`: each grammar loads with the first file that needs it. The token events of CodeFile need
// `useTokenTransformer` here, because a working pool ignores that option of the view.
export const HIGHLIGHTER_OPTIONS: WorkerInitializationRenderOptions = { theme: THEME, useTokenTransformer: true };
