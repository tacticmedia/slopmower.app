import { describe, expect, it } from 'vitest';
import { centredScrollTop, dragScrollStepPx, isBelowHeader } from './scrollTarget';

describe('ScrollTargetTest', () => {
    it('Centres a span in the viewport below the header', () => {
        // 600 px free below a 100 px header; a 20 px line at 5000 sits 290 px below the header.
        expect(centredScrollTop({ top: 5000, bottom: 5020 }, 100, 700)).toBe(4610);
    });

    it('Puts a span taller than the free space right below the header', () => {
        expect(centredScrollTop({ top: 5000, bottom: 6000 }, 100, 700)).toBe(4900);
    });

    it('Never scrolls above the page top', () => {
        expect(centredScrollTop({ top: 40, bottom: 60 }, 100, 700)).toBe(0);
    });

    it('Tells whether a span is in view below the header', () => {
        expect(isBelowHeader({ top: 100, bottom: 120 }, 100, 700)).toBe(true);
        expect(isBelowHeader({ top: 90, bottom: 110 }, 100, 700)).toBe(false);
        expect(isBelowHeader({ top: 690, bottom: 710 }, 100, 700)).toBe(false);
    });

    it('Scrolls a drag only in the bands at the edges of the viewport, faster deeper in, up to a maximum', () => {
        // A viewport from 100 to 700 px: bands 100 to 148 and 652 to 700.
        expect([dragScrollStepPx(400, 100, 700), dragScrollStepPx(148, 100, 700), dragScrollStepPx(652, 100, 700)]).toEqual([0, 0, 0]);
        expect([dragScrollStepPx(140, 100, 700), dragScrollStepPx(110, 100, 700), dragScrollStepPx(-500, 100, 700)]).toEqual([-4, -19, -40]);
        expect([dragScrollStepPx(660, 100, 700), dragScrollStepPx(690, 100, 700), dragScrollStepPx(1500, 100, 700)]).toEqual([4, 19, 40]);
    });
});
