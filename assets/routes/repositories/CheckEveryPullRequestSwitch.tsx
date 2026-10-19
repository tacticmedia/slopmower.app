import { ToggleSwitch } from '@primer/react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import { ActionErrorMessage } from '../../layout/ActionErrorMessage';
import { HelpLink } from '../../layout/HelpLink';
import { HELP } from '../../layout/helpLinks';
import styles from './RepositoryRoute.module.css';

const LABEL_ID = 'check-every-pull-request';

/**
 * R8 through the action of the repository route. While the write runs, the switch shows the requested value.
 */
export function CheckEveryPullRequestSwitch({ enabled }: { enabled: boolean }) {
    const fetcher = useFetcher<ActionResult>();
    const pending = 'idle' !== fetcher.state;

    return (
        <div className={styles.setting}>
            <span id={LABEL_ID}>Check every pull request</span>
            <ToggleSwitch
                aria-labelledby={LABEL_ID}
                size="small"
                checked={pending ? 'true' === fetcher.formData?.get('enabled') : enabled}
                loading={pending}
                onChange={(checked) => {
                    if (!pending) {
                        void fetcher.submit({ intent: 'check-every-pull-request', enabled: String(checked) }, { method: 'post' });
                    }
                }}
            />
            <HelpLink href={HELP.checkEveryPullRequest} />
            <ActionErrorMessage result={fetcher.data} />
        </div>
    );
}
