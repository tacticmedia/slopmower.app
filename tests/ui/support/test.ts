import { test as base } from '@playwright/test';
import { installFakeEventSource, Live } from './live';
import { MockApi } from './mockApi';

export { expect } from '@playwright/test';

export const test = base.extend<{ api: MockApi; live: Live }>({
    api: [
        async ({ page }, use) => {
            await page.addInitScript(installFakeEventSource);
            const api = new MockApi(page);
            await api.install();
            await use(api);
            await page.unrouteAll({ behavior: 'ignoreErrors' });
            api.assertNoProblems();
        },
        { auto: true },
    ],
    live: async ({ page }, use) => {
        await use(new Live(page));
    },
});
