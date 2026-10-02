import { afterEach, describe, expect, it, vi } from 'vitest';
import { expiresAtEndOf, expiryDateBounds } from './expiry';

const MAXIMUM_LIFETIME_MILLISECONDS = 366 * 24 * 60 * 60 * 1000;

describe('ExpiryTest', () => {
    afterEach(() => {
        vi.unstubAllEnvs();
    });

    it('The last offered day ends at 23:59 local time, sent as UTC with second precision, within 366 days', () => {
        vi.stubEnv('TZ', 'Australia/Sydney');
        const now = new Date('2026-10-02T00:30:00+10:00');

        const { min, max } = expiryDateBounds(now);
        const expiresAt = expiresAtEndOf(max);

        expect(min).toBe('2026-10-02');
        expect(max).toBe('2027-10-02');
        expect(expiresAt).toBe('2027-10-02T13:59:00Z');
        expect(Date.parse(expiresAt) - now.getTime()).toBeLessThanOrEqual(MAXIMUM_LIFETIME_MILLISECONDS);
    });

    it('With a DST fall-back in the window, the last offered day still ends within 366 days', () => {
        vi.stubEnv('TZ', 'America/New_York');
        const now = new Date('2027-11-06T00:30:00-04:00');

        const { max } = expiryDateBounds(now);

        expect(max).toBe('2028-11-04');
        expect(Date.parse(expiresAtEndOf(max)) - now.getTime()).toBeLessThanOrEqual(MAXIMUM_LIFETIME_MILLISECONDS);
        expect(Date.parse(expiresAtEndOf('2028-11-05')) - now.getTime()).toBeGreaterThan(MAXIMUM_LIFETIME_MILLISECONDS);
    });
});
