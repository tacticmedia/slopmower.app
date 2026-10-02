import type { DateTime } from '../api/types/common';

/**
 * T2 accepts up to now plus exactly 366 x 24 hours.
 */
const MAXIMUM_LIFETIME_MILLISECONDS = 366 * 24 * 60 * 60 * 1000;

export type ExpiryDateBounds = {
    min: string;
    max: string;
};

/**
 * The last offered day is the latest day that ends within the T2 limit. Counting calendar days is not enough: a DST
 * change in the window moves the end of a local day against UTC.
 */
export function expiryDateBounds(now: Date): ExpiryDateBounds {
    const limit = now.getTime() + MAXIMUM_LIFETIME_MILLISECONDS;
    const lastDay = new Date(limit);

    while (Date.parse(expiresAtEndOf(localDate(lastDay))) > limit) {
        lastDay.setDate(lastDay.getDate() - 1);
    }

    return { min: localDate(now), max: localDate(lastDay) };
}

/**
 * The token stops working at the end of the chosen day in the browser's time zone.
 */
export function expiresAtEndOf(date: string): DateTime {
    return new Date(`${date}T23:59:00`).toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function localDate(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}
