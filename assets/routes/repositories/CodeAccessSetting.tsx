import { ActionList, ActionMenu, Dialog } from '@primer/react';
import { type RefObject, useEffect, useId, useRef, useState } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import { CODE_ACCESSES, type CodeAccess } from '../../api/types/common';
import { ActionErrorMessage } from '../../layout/ActionErrorMessage';
import { HelpLink } from '../../layout/HelpLink';
import { HELP } from '../../layout/helpLinks';
import { useSubmitOnce } from '../../layout/useSubmitOnce';
import styles from './CodeAccess.module.css';
import { CODE_ACCESS_NAMES } from './CodeAccessChoice';
import routeStyles from './RepositoryRoute.module.css';

const CONS: Record<CodeAccess, string> = {
    clone: 'Slopmower keeps a full copy of the repository with its history.',
    pass_through: 'Each read waits for GitHub; code does not load during a GitHub outage.',
};

/** What a switch to the mode does. */
const SWITCH_EFFECTS: Record<CodeAccess, string> = {
    clone: 'Slopmower clones the repository again. Code loads after the clone.',
    pass_through: 'Slopmower deletes its copy of the repository. Reads go to GitHub from then on.',
};

/** The mode of a GitHub repository. A switch is R10 through the action of the repository route, after a confirmation. */
export function CodeAccessSetting({ codeAccess }: { codeAccess: CodeAccess }) {
    const [switchTo, setSwitchTo] = useState<CodeAccess | null>(null);
    const anchor = useRef<HTMLButtonElement>(null);

    return (
        <div className={routeStyles.setting} data-testid="code-access">
            <span>
                Code access: <strong>{CODE_ACCESS_NAMES[codeAccess]}</strong>
            </span>
            <span className={routeStyles.meta}>{CONS[codeAccess]}</span>
            <ActionMenu anchorRef={anchor}>
                <ActionMenu.Button size="small">Change</ActionMenu.Button>
                <ActionMenu.Overlay>
                    <ActionList selectionVariant="single">
                        {CODE_ACCESSES.map((mode) => (
                            <ActionList.Item key={mode} selected={mode === codeAccess} onSelect={() => setSwitchTo(mode === codeAccess ? null : mode)}>
                                {CODE_ACCESS_NAMES[mode]}
                            </ActionList.Item>
                        ))}
                    </ActionList>
                </ActionMenu.Overlay>
            </ActionMenu>
            <HelpLink href={HELP.codeAccess} />
            {null !== switchTo && <SwitchDialog codeAccess={switchTo} returnFocusRef={anchor} onClose={() => setSwitchTo(null)} />}
        </div>
    );
}

type SwitchDialogProps = {
    codeAccess: CodeAccess;
    returnFocusRef: RefObject<HTMLElement | null>;
    onClose: () => void;
};

/** The route action answers null after the write, and the dialog closes. */
function SwitchDialog({ codeAccess, returnFocusRef, onClose }: SwitchDialogProps) {
    const fetcher = useFetcher<ActionResult>();
    const submitOnce = useSubmitOnce(fetcher.submit);
    const formId = useId();
    const busy = 'idle' !== fetcher.state;
    const closeUnlessBusy = (): void => {
        if (!busy) {
            onClose();
        }
    };

    useEffect(() => {
        if ('idle' === fetcher.state && null === fetcher.data) {
            onClose();
        }
    }, [fetcher.state, fetcher.data, onClose]);

    return (
        <Dialog
            title={`Switch to ${CODE_ACCESS_NAMES[codeAccess]}`}
            onClose={closeUnlessBusy}
            returnFocusRef={returnFocusRef}
            footerButtons={[
                { content: 'Cancel', onClick: closeUnlessBusy, disabled: busy },
                { content: 'Switch', buttonType: 'primary', type: 'submit', form: formId, loading: busy },
            ]}
        >
            <fetcher.Form method="post" id={formId} className={styles.form} onSubmit={submitOnce}>
                <input type="hidden" name="intent" value="code-access" />
                <input type="hidden" name="codeAccess" value={codeAccess} />
                <p>{SWITCH_EFFECTS[codeAccess]}</p>
                <ActionErrorMessage result={fetcher.data} />
            </fetcher.Form>
        </Dialog>
    );
}
