import { InlineMessage } from '@primer/react/experimental';
import type { ActionResult } from '../api/actionResult';

export function ActionErrorMessage({ result }: { result: ActionResult | undefined }) {
    if (!result) {
        return null;
    }

    return (
        <InlineMessage variant="critical" role="alert" data-testid="action-error">
            {result.error}
        </InlineMessage>
    );
}
