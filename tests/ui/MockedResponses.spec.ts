import { me } from './support/builders';
import { json } from './support/mockApi';
import { SchemaViolation } from './support/schemas';
import { expect, test } from './support/test';

test.describe('MockedResponses', () => {
    test('Every mocked response validates against its schema', async ({ page, api }) => {
        const invalidMe = { ...me(), user: { ...me().user, login: 42 } };
        expect(() => json('me.schema.json', invalidMe)).toThrow(SchemaViolation);
        api.on('GET', '/api/v1/me', () => json('me.schema.json', invalidMe));

        await page.goto('/');

        await expect(page.locator('[data-testid="route-error"]')).toContainText('does not validate against me.schema.json');
        expect(() => api.assertNoProblems()).toThrow(/GET \/api\/v1\/me: The body does not validate against me\.schema\.json: data\/user\/login must be string/);
        api.takeProblems();
    });
});
