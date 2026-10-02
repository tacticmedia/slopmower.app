import { ApiError } from './client';

/**
 * The data of a route action: null after success, else the `message` of the Error body.
 */
export type ActionResult = { error: string } | null;

/**
 * A 401 and failures that are not API errors reach the error boundary.
 */
export function actionError(error: unknown): ActionResult {
    if (error instanceof ApiError && 401 !== error.status) {
        return { error: error.message };
    }

    throw error;
}

export async function writeResult(write: () => Promise<unknown>): Promise<ActionResult> {
    try {
        await write();

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
