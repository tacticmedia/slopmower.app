import { describe, expect, it } from 'vitest';
import { characterColumn } from './characterColumn';

describe('CharacterColumnTest', () => {
    it('Counts a character outside the BMP as one column', () => {
        const line = "    $label = '\u{1F600}'; new TaxRate();";

        expect(characterColumn(`<?php\n${line}\n`, 2, line.indexOf('TaxRate'))).toBe(Array.from(line).indexOf('T') + 1);
    });
});
