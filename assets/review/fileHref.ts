/**
 * The shell path of a file. Each path segment is encoded; the slashes stay, as in the `/reviews/{id}/files/{path}` route.
 */
export function fileHref(reviewId: string, path: string): string {
    return `/reviews/${encodeURIComponent(reviewId)}/files/${path.split('/').map(encodeURIComponent).join('/')}`;
}
