import { captureException } from '@sentry/react';
import { removeSessionDraft } from '../layout/useSessionDraft';
import { ApiError } from './client';

/**
 * The data of a route action: null after success, else the `message` of the Error body.
 */
export type ActionResult = { error: string } | null;

/**
 * A 401 and failures that are not API errors reach the error boundary. The `beforeSend` of `assets/sentry.ts` drops
 * the report of a status below 500.
 */
export function actionError(error: unknown): { error: string } {
    if (error instanceof ApiError && 401 !== error.status) {
        captureException(error);

        return { error: error.message };
    }

    throw error;
}

/** Removes the session draft `draftKey` when the write succeeds (D109). */
export async function writeResult(write: () => Promise<unknown>, draftKey?: string): Promise<ActionResult> {
    try {
        await write();
        if (undefined !== draftKey) {
            removeSessionDraft(draftKey);
        }

        return null;
    } catch (error) {
        return actionError(error);
    }
}

/**
 * A trimmed form value, or null when it is empty.
 */
export function optionalText(value: FormDataEntryValue | null): string | null {
    const text = 'string' === typeof value ? value.trim() : '';

    return '' === text ? null : text;
}
