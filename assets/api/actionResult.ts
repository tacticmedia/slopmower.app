import { captureException } from '@sentry/react';
import { removeSessionDraft } from '../layout/useSessionDraft';
import { ApiError } from './client';
import type { InvalidInputDetails } from './types/error';

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

/**
 * As {@link actionError}, with only the reasons of the violations: no "Invalid input." and no field name. For a screen
 * where the place of the message says what it is about. An error without a violation keeps its message.
 */
export function refusalReasons(error: unknown): { error: string } {
    const failure = actionError(error);
    const reasons = violationsOf(error).map(({ message }) => message);

    return 0 === reasons.length ? failure : { error: reasons.join(' ') };
}

/** Removes the session draft `draftKey` when the write succeeds (D109). */
export async function writeResult(write: () => Promise<unknown>, draftKey?: string, toResult: (error: unknown) => { error: string } = actionError): Promise<ActionResult> {
    try {
        await write();
        if (undefined !== draftKey) {
            removeSessionDraft(draftKey);
        }

        return null;
    } catch (error) {
        return toResult(error);
    }
}

/** A write that a form refused: the `message` of the Error body, and its violations for the fields. */
export type InputFailure = {
    message: string;
    violations: InvalidInputDetails['violations'];
};

/** The failure of a 422 or a 404 of a body ID, for the form. Other errors go to the error page. */
export function inputFailure(error: unknown): InputFailure {
    if (error instanceof ApiError && ('invalid_input' === error.code || 'not_found' === error.code)) {
        return { message: error.message, violations: violationsOf(error) };
    }

    throw error;
}

function violationsOf(error: unknown): InvalidInputDetails['violations'] {
    return error instanceof ApiError && null !== error.details && 'violations' in error.details ? error.details.violations : [];
}

/** The messages that no field of the form shows, for example a limit or an unknown ID. */
export function formMessages({ message, violations }: InputFailure, fields: readonly string[]): string[] {
    return 0 === violations.length ? [message] : violations.filter(({ field }) => !fields.includes(field)).map((violation) => violation.message);
}

/**
 * A trimmed form value, or null when it is empty.
 */
export function optionalText(value: FormDataEntryValue | null): string | null {
    const text = 'string' === typeof value ? value.trim() : '';

    return '' === text ? null : text;
}
