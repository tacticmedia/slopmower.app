/**
 * The 12 characters that the default review title of V1 uses.
 */
export function shortSha(commitSha: string): string {
    return commitSha.slice(0, 12);
}
