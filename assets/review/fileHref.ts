/**
 * The shell path of a file. Each path segment is encoded; the slashes stay, as in the `/reviews/{id}/files/{path}` route.
 */
export function fileHref(reviewId: string, path: string): string {
    return `/reviews/${encodeURIComponent(reviewId)}/files/${encodedPath(path)}`;
}

/** The shell path of a directory: the Code tab for the root, `/reviews/{id}/tree/{path}` for the others. */
export function directoryHref(reviewId: string, dir: string): string {
    const review = `/reviews/${encodeURIComponent(reviewId)}`;

    return '' === dir ? review : `${review}/tree/${encodedPath(dir)}`;
}

function encodedPath(path: string): string {
    return path.split('/').map(encodeURIComponent).join('/');
}
