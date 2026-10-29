const BYTES_PER_KIB = 1024;

const UNITS = ['KB', 'MB', 'GB', 'TB'] as const;

/** The largest file that V7 renders. */
export const MAX_RENDERED_BYTES = BYTES_PER_KIB ** 2;

/** As GitHub writes sizes: `512 Bytes`, `3.11 KB`, `79.1 KB`, `2.5 MB`, `2 GB`, with binary units. */
export function formatSize(bytes: number): string {
    if (bytes < BYTES_PER_KIB) {
        return `${bytes} Bytes`;
    }
    let value = bytes / BYTES_PER_KIB;
    let unit = 0;
    while (value >= BYTES_PER_KIB && unit < UNITS.length - 1) {
        value /= BYTES_PER_KIB;
        unit += 1;
    }

    return `${Number(value.toPrecision(3))} ${UNITS[unit]}`;
}
