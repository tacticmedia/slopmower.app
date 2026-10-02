/** Q11 counts the reason after trimming. */
export const REASON_MIN_CHARACTERS = 20;

export type NoteRule = 'optional' | 'required' | 'reason';

/**
 * Counts the code points of the trimmed text, as `Assert\Length` on the server does.
 */
export function noteAccepted(text: string, rule: NoteRule): boolean {
    const trimmed = text.trim();

    switch (rule) {
        case 'optional':
            return true;
        case 'required':
            return '' !== trimmed;
        case 'reason':
            return [...trimmed].length >= REASON_MIN_CHARACTERS;
    }
}
