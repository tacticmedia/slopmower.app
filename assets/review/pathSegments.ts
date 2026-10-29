export type PathSegment = { name: string; path: string };

/** `src/Billing/A.php` gives `src`, `src/Billing`, and `src/Billing/A.php`. The root `""` gives none. */
export function pathSegments(path: string): PathSegment[] {
    if ('' === path) {
        return [];
    }
    const names = path.split('/');

    return names.map((name, index) => ({ name, path: names.slice(0, index + 1).join('/') }));
}
