/** `IssueKey` of common.schema.json, between word boundaries. */
const ISSUE_KEY = /\b([A-Z]{2,10})-[1-9][0-9]{0,8}\b/g;

export type TextPart = {
    text: string;
    /** The screen of the issue when `text` is an issue key, else null. */
    issueHref: string | null;
};

/**
 * The parts of `text`, in order. A match of the key pattern is an issue key only when `projectKeys` has its project
 * (D125), so `UTF-8` stays text.
 */
export function splitIssueKeys(text: string, projectKeys: readonly string[]): TextPart[] {
    const parts: TextPart[] = [];
    let textStart = 0;

    for (const match of text.matchAll(ISSUE_KEY)) {
        if (!projectKeys.includes(match[1] ?? '')) {
            continue;
        }
        if (match.index > textStart) {
            parts.push({ text: text.slice(textStart, match.index), issueHref: null });
        }
        parts.push({ text: match[0], issueHref: `/issues/${match[0]}` });
        textStart = match.index + match[0].length;
    }
    if (textStart < text.length) {
        parts.push({ text: text.slice(textStart), issueHref: null });
    }

    return parts;
}
