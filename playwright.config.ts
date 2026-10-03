import { defineConfig, devices } from '@playwright/test';

/** A second checkout sets its own port, for example `export UI_PORT=4135` in `.env.make.local`. */
const port = Number(process.env.UI_PORT ?? 4173);
const baseURL = `http://localhost:${port}`;
const viteConfig = 'tests/ui/vite.config.ts';

export default defineConfig({
    testDir: 'tests/ui',
    testMatch: '*.spec.ts',
    outputDir: 'var/ui/test-results',
    fullyParallel: true,
    forbidOnly: true,
    retries: 0,
    reporter: [['list']],
    use: {
        ...devices['Desktop Chrome'],
        baseURL,
        locale: 'en-US',
        timezoneId: 'UTC',
        trace: 'retain-on-failure',
    },
    webServer: {
        command: `vite build --config ${viteConfig} && vite preview --config ${viteConfig} --port ${port} --strictPort`,
        url: baseURL,
        reuseExistingServer: false,
        timeout: 120_000,
    },
});
