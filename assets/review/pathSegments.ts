export type PathSegment = { name: string; path: string };

/** `src/Billing/A.php` gives `src`, `src/Billing`, and `src/Billing/A.php`. The root `""` gives none. */
export function pathSegments(path: string): PathSegment[] {
    if ('' === path) {
        return [];
    }
    const names = path.split('/');

    return names.map((name, index) => ({ name, path: names.slice(0, index + 1).join('/') }));
}

const BYTES_PER_KIB = 1024;

/** As GitHub writes sizes: `512 Bytes`, `3.11 KB`, `79.1 KB`, `2.5 MB`, with binary units. */
export function formatSize(bytes: number): string {
    if (bytes < BYTES_PER_KIB) {
        return `${bytes} Bytes`;
    }
    const [value, unit] = bytes < BYTES_PER_KIB ** 2 ? [bytes / BYTES_PER_KIB, 'KB'] : [bytes / BYTES_PER_KIB ** 2, 'MB'];

    return `${Number(value.toPrecision(3))} ${unit}`;
}
