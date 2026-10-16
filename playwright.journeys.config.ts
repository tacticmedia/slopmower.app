import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { defineConfig } from '@playwright/test';
import type { QaScreenshotsOptions } from '@tacticmedia/qa-capture/playwright';
import ui from './playwright.config';

/** A second checkout sets its own port, for example `export JOURNEY_PORT=4136` in `.env.make.local`. */
const port = Number(process.env.JOURNEY_PORT ?? 4174);
const baseURL = `http://localhost:${port}`;
const viteConfig = 'tests/ui/vite.config.ts';
// Vite empties its output directory, so the UI suite and the journeys build into their own.
const build = fileURLToPath(new URL('var/journeys/build', import.meta.url));

export default defineConfig<{ qaScreenshotsOptions: QaScreenshotsOptions }>({
    ...ui,
    testDir: 'tests/journeys',
    outputDir: 'var/journeys/test-results',
    // Each capture takes 10 full-page screenshots, and a journey makes up to 7 captures.
    timeout: 120_000,
    globalSetup: createRequire(import.meta.url).resolve('@tacticmedia/qa-capture/playwright/global-setup'),
    use: {
        ...ui.use,
        baseURL,
        qaScreenshotsOptions: { root: fileURLToPath(new URL('var/screenshots', import.meta.url)) },
    },
    webServer: {
        command: `vite build --config ${viteConfig} --outDir "${build}" && vite preview --config ${viteConfig} --outDir "${build}" --port ${port} --strictPort`,
        url: baseURL,
        reuseExistingServer: false,
        timeout: 120_000,
    },
});
