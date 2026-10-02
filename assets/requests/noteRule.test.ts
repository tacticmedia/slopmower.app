import { describe, expect, it } from 'vitest';
import { noteAccepted } from './noteRule';

describe('NoteRuleTest', () => {
    it('Counts the reason in code points after trimming', () => {
        // 19 code points, but 20 UTF-16 code units.
        expect(noteAccepted(`   😀${'a'.repeat(18)}   `, 'reason')).toBe(false);
        expect(noteAccepted(`😀${'a'.repeat(19)}`, 'reason')).toBe(true);
    });
});
