import { rawHref } from '../routes/reviews/treeData';
import { directoryHref, fileHref } from './fileHref';

export type ReadmeBase = {
    reviewId: string;
    /** The directory of the README, `""` for the root. */
    dir: string;
    /** The directories of `dir`, by path. V7 refuses a directory, so a link to one must go to its directory view. */
    directories: ReadonlySet<string>;
    /** Browser only (D114): an image resolves to its repository path, which {@link GitHubImage} reads from GitHub. */
    imagesFromGitHub?: boolean;
};

const WITH_SCHEME = /^([a-z][a-z0-9+.-]*:|\/\/)/i;

/**
 * Resolves a relative URL of a README as GitHub does: against the directory of the README, or against the root for a
 * leading `/`. An image reads the bytes through V8, or is its repository path for GitHub. A link opens a file or a
 * directory of the review commit. Absolute URLs and in-page anchors stay.
 */
export function readmeUrl(url: string, attribute: 'href' | 'src', base: ReadmeBase): string {
    if ('' === url || url.startsWith('#') || WITH_SCHEME.test(url)) {
        return url;
    }
    const hashAt = url.indexOf('#');
    const hash = -1 === hashAt ? '' : url.slice(hashAt);
    const target = (-1 === hashAt ? url : url.slice(0, hashAt)).replace(/\?.*$/, '');
    const path = resolve(target.startsWith('/') ? '' : base.dir, target);

    if ('src' === attribute) {
        return true === base.imagesFromGitHub ? path : rawHref(encodeURIComponent(base.reviewId), path);
    }

    return '' === path || target.endsWith('/') || base.directories.has(path) ? directoryHref(base.reviewId, path) : `${fileHref(base.reviewId, path)}${hash}`;
}

/** Joins and normalises `.` and `..`. A `..` above the root stays at the root. */
function resolve(dir: string, relative: string): string {
    const segments: string[] = '' === dir ? [] : dir.split('/');
    for (const segment of relative.split('/')) {
        if ('..' === segment) {
            segments.pop();
        } else if ('' !== segment && '.' !== segment) {
            segments.push(decoded(segment));
        }
    }

    return segments.join('/');
}

/** A malformed escape, for example `%PU` of `%PUBLIC_URL%`, stays as it is. */
function decoded(segment: string): string {
    try {
        return decodeURIComponent(segment);
    } catch {
        return segment;
    }
}
