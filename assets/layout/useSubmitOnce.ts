import { type FormEvent, useRef } from 'react';
import type { FetcherSubmitFunction } from 'react-router';

/**
 * The `onSubmit` of a fetcher form that sends one write at a time. Primer's `loading` only sets `aria-disabled`, and the
 * fetcher state commits in a transition, so neither stops a quick second click or Enter.
 */
export function useSubmitOnce(submit: FetcherSubmitFunction): (event: FormEvent<HTMLFormElement>) => void {
    const submitting = useRef(false);

    return (event) => {
        event.preventDefault();
        if (!submitting.current) {
            submitting.current = true;
            void submit(event.currentTarget).finally(() => {
                submitting.current = false;
            });
        }
    };
}
