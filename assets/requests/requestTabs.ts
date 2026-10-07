/**
 * The `tab` query value of the request detail. docs/specs names the shell path `/requests/{id}` only, so the tab is
 * no path.
 */
export const CHANGES_TAB = 'changes';

/** The element ID of a commit section on the Changes tab, and the hash that scrolls to it. */
export function commitSectionId(commitSha: string): string {
    return `commit-${commitSha}`;
}
