import { isMacOS } from '@primer/behaviors/utils';

/**
 * Mod, Shift when `shift`, and `key`, a `KeyboardEvent.key` value. Mod is Command on macOS and Control elsewhere, as
 * in Primer `KeybindingHint`.
 */
export type ModShortcut = { key: string; shift: boolean };

/** Alt, or both Command and Control, never match. */
export function isPressed({ key, shift }: ModShortcut, event: KeyboardEvent): boolean {
    const mod = isMacOS() ? event.metaKey && !event.ctrlKey : event.ctrlKey && !event.metaKey;

    return mod && !event.altKey && shift === event.shiftKey && key.toLowerCase() === event.key.toLowerCase();
}

/** The `keys` of `KeybindingHint`. */
export function hintKeys({ key, shift }: ModShortcut): string {
    return ['Mod', ...(shift ? ['Shift'] : []), key].join('+');
}

export function ariaKeyShortcuts({ key, shift }: ModShortcut): string {
    return [isMacOS() ? 'Meta' : 'Control', ...(shift ? ['Shift'] : []), key].join('+');
}
