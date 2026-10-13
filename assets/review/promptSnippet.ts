/**
 * The ready agent prompt of the review header, decision D8.
 */
export function agentPrompt(reviewId: string): string {
    return `Work on Slopmower review ${reviewId}`;
}

/** The second prompt of the review header: the agent reviews the code and suggests requests. */
export function suggestionPrompt(reviewId: string): string {
    return `Review the code of Slopmower review ${reviewId} and suggest changes`;
}
