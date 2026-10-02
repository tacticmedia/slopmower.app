import { describe, expect, it } from 'vitest';
import { claimAge } from './claimAge';

const CLAIMED_AT = '2026-09-29T07:40:00Z';

describe('ClaimAgeTest', () => {
    it('Formats the claim age in minutes, hours, and days', () => {
        const ageAt = (now: string): string => claimAge(CLAIMED_AT, new Date(now));

        expect(ageAt('2026-09-29T07:40:59Z')).toBe('less than a minute');
        expect(ageAt('2026-09-29T07:41:00Z')).toBe('1 minute');
        expect(ageAt('2026-09-29T08:39:59Z')).toBe('59 minutes');
        expect(ageAt('2026-09-29T08:40:00Z')).toBe('1 hour');
        expect(ageAt('2026-09-30T07:39:59Z')).toBe('23 hours');
        expect(ageAt('2026-09-30T07:40:00Z')).toBe('1 day');
        expect(ageAt('2026-10-02T19:00:00Z')).toBe('3 days');
    });
});
