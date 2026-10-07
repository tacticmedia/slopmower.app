import { describe, expect, it } from 'vitest';
import { blameAgeScale } from './blameAge';

const NOW = new Date('2026-10-08T00:00:00Z');

function daysAgo(days: number): string {
    return new Date(NOW.getTime() - days * 86_400_000).toISOString();
}

describe('BlameAgeTest', () => {
    it('Gives 9 to a commit of this week and 0 to one older than 450 days', () => {
        expect(blameAgeScale(daysAgo(1), NOW)).toBe(9);
        expect(blameAgeScale(daysAgo(450), NOW)).toBe(0);
    });

    it('Steps down at each limit', () => {
        expect([7, 14, 21, 45, 90, 120, 180, 270].map((limit) => [blameAgeScale(daysAgo(limit - 1), NOW), blameAgeScale(daysAgo(limit), NOW)])).toEqual([
            [9, 8],
            [8, 7],
            [7, 6],
            [6, 5],
            [5, 4],
            [4, 3],
            [3, 2],
            [2, 1],
        ]);
    });
});
