import { Checkbox, FormControl } from '@primer/react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import type { ViewedFileInput } from '../api/types/inputs';
import { ActionErrorMessage } from '../layout/ActionErrorMessage';

/** The JSON body that the checkbox submits to the action of the file route: V12. */
export type ViewedIntent = { intent: 'viewed'; input: ViewedFileInput };

type Props = {
    path: string;
    viewed: boolean;
    /** True on a closed review: V12 refuses. */
    disabled: boolean;
};

/**
 * Shows the submitted value until the review data reloads, and the stored value again when V12 fails.
 */
export function ViewedCheckbox({ path, viewed, disabled }: Props) {
    const fetcher = useFetcher<ActionResult>();
    const busy = 'idle' !== fetcher.state;
    const submitted = fetcher.json as ViewedIntent | undefined;

    return (
        <>
            <FormControl disabled={disabled}>
                <Checkbox
                    checked={submitted?.input.viewed ?? viewed}
                    onChange={(event) => {
                        if (!busy) {
                            const intent: ViewedIntent = { intent: 'viewed', input: { path, viewed: event.target.checked } };
                            void fetcher.submit(intent, { method: 'post', encType: 'application/json' });
                        }
                    }}
                    data-testid="viewed-checkbox"
                />
                <FormControl.Label>Viewed</FormControl.Label>
            </FormControl>
            <ActionErrorMessage result={busy ? undefined : fetcher.data} />
        </>
    );
}
