import type { DateTime } from '../api/types/common';

const MINUTE_MILLISECONDS = 60_000;
const HOUR_MILLISECONDS = 60 * MINUTE_MILLISECONDS;
const DAY_MILLISECONDS = 24 * HOUR_MILLISECONDS;

/**
 * Decision D6: how long the agent has held the request, rounded down to the largest whole unit.
 */
export function claimAge(claimedAt: DateTime, now: Date): string {
    const elapsedMilliseconds = Math.max(0, now.getTime() - Date.parse(claimedAt));

    if (elapsedMilliseconds < MINUTE_MILLISECONDS) {
        return 'less than a minute';
    }
    if (elapsedMilliseconds < HOUR_MILLISECONDS) {
        return count(Math.floor(elapsedMilliseconds / MINUTE_MILLISECONDS), 'minute');
    }
    if (elapsedMilliseconds < DAY_MILLISECONDS) {
        return count(Math.floor(elapsedMilliseconds / HOUR_MILLISECONDS), 'hour');
    }

    return count(Math.floor(elapsedMilliseconds / DAY_MILLISECONDS), 'day');
}

function count(value: number, unit: string): string {
    return `${value} ${unit}${1 === value ? '' : 's'}`;
}
