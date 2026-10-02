import type { CommitDiffFile } from '../api/types/commit-diff';

const SUBMODULE_MODE = '160000';

/**
 * The text that stands in for the diff of a file. R7 has no blob for a submodule, and `parseDiffFromFile()` gives no
 * hunks for equal contents, such as a pure rename, so the diff would be empty.
 *
 * @returns Null when the diff can render
 */
export function diffPlaceholder(file: CommitDiffFile): string | null {
    if (file.binary) {
        return 'Binary file not shown.';
    }

    if (file.tooLarge) {
        return 'File not shown: a side is above the 1 MiB limit.';
    }

    if (SUBMODULE_MODE === file.oldMode || SUBMODULE_MODE === file.newMode) {
        return 'Submodule change not shown.';
    }

    return file.oldBlobSha === file.newBlobSha ? 'The content is unchanged.' : null;
}
