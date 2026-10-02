import { Banner, Dialog, FormControl, Select, TextInput } from '@primer/react';
import { type FormEvent, useId, useRef } from 'react';
import { useFetcher } from 'react-router';
import type { Repository } from '../api/types/repository';
import type { CreateTokenFailure, CreateTokenResult, SettingsIntent } from '../routes/settings/settingsData';
import { expiresAtEndOf, expiryDateBounds } from './expiry';
import { IssuedToken } from './IssuedToken';
import styles from './Settings.module.css';

const FIELDS = ['name', 'repositoryId', 'expiresAt'] as const;
type Field = (typeof FIELDS)[number];

type Props = {
    repositories: Repository[];
    mcpUrl: string;
    onClose: () => void;
};

/**
 * The fetcher lives only while the dialog is open, so closing it drops the secret. While T2 runs, the dialog neither
 * submits again nor closes, so every created secret is shown.
 */
export function CreateTokenDialog({ repositories, mcpUrl, onClose }: Props) {
    const fetcher = useFetcher<CreateTokenResult | null>();
    const formId = useId();
    const nameInput = useRef<HTMLInputElement>(null);
    // The fetcher state changes in a transition, so a second event in the same frame still sees "idle".
    const submitting = useRef(false);
    const result = fetcher.data ?? null;
    const busy = (): boolean => submitting.current || 'idle' !== fetcher.state;
    const closeUnlessBusy = (): void => {
        if (!busy()) {
            onClose();
        }
    };

    if (null !== result && null !== result.token) {
        return (
            <Dialog title="Token created" onClose={onClose} footerButtons={[{ content: 'Done', buttonType: 'primary', onClick: onClose }]}>
                <IssuedToken token={result.token} mcpUrl={mcpUrl} />
            </Dialog>
        );
    }

    const violations = result?.failure.violations ?? [];
    const fieldMessage = (field: Field): string | undefined => violations.find((violation) => violation.field === field)?.message;
    const nameMessage = fieldMessage('name');
    const repositoryMessage = fieldMessage('repositoryId');
    const expiryMessage = fieldMessage('expiresAt');
    const formMessages = null === result ? [] : formViolationMessages(result.failure);
    const { min, max } = expiryDateBounds(new Date());

    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        if (busy()) {
            return;
        }
        submitting.current = true;
        const form = new FormData(event.currentTarget);
        const repositoryId = String(form.get('repositoryId') ?? '');
        const expiresOn = String(form.get('expiresOn') ?? '');
        const intent: SettingsIntent = {
            intent: 'create',
            input: {
                name: String(form.get('name') ?? ''),
                repositoryId: '' === repositoryId ? null : repositoryId,
                expiresAt: '' === expiresOn ? null : expiresAtEndOf(expiresOn),
            },
        };
        void fetcher.submit(intent, { method: 'post', action: '/settings', encType: 'application/json' }).finally(() => {
            submitting.current = false;
        });
    };

    return (
        <Dialog
            title="Create token"
            onClose={closeUnlessBusy}
            initialFocusRef={nameInput}
            footerButtons={[
                { content: 'Cancel', onClick: closeUnlessBusy, disabled: 'idle' !== fetcher.state },
                { content: 'Create', buttonType: 'primary', type: 'submit', form: formId, loading: 'idle' !== fetcher.state },
            ]}
        >
            <form id={formId} onSubmit={submit} className={styles.form}>
                {formMessages.map((message) => (
                    <Banner key={message} variant="critical" title="The token was not created" description={message} />
                ))}
                <FormControl required>
                    <FormControl.Label>Name</FormControl.Label>
                    <TextInput name="name" ref={nameInput} block maxLength={64} autoComplete="off" />
                    <FormControl.Caption>
                        The agent name on every comment and change that the token makes. 1 to 64 characters: letters, digits, spaces, and{' '}
                        <code>. _ @ ( ) : -</code>. The first character is a letter or a digit.
                    </FormControl.Caption>
                    {undefined !== nameMessage && <FormControl.Validation variant="error">{nameMessage}</FormControl.Validation>}
                </FormControl>
                <FormControl>
                    <FormControl.Label>Scope</FormControl.Label>
                    <Select name="repositoryId" block>
                        <Select.Option value="">All repositories</Select.Option>
                        {repositories.map((repository) => (
                            <Select.Option key={repository.id} value={repository.id}>
                                {repository.name}
                            </Select.Option>
                        ))}
                    </Select>
                    <FormControl.Caption>A token for one repository cannot see your other repositories.</FormControl.Caption>
                    {undefined !== repositoryMessage && <FormControl.Validation variant="error">{repositoryMessage}</FormControl.Validation>}
                </FormControl>
                <FormControl>
                    <FormControl.Label>Expiry date</FormControl.Label>
                    <TextInput name="expiresOn" type="date" min={min} max={max} />
                    <FormControl.Caption>Optional. The token stops working at the end of this day.</FormControl.Caption>
                    {undefined !== expiryMessage && <FormControl.Validation variant="error">{expiryMessage}</FormControl.Validation>}
                </FormControl>
            </form>
        </Dialog>
    );
}

/**
 * A failure without a form field, such as the token limit or an unknown repository, shows above the form.
 */
function formViolationMessages({ message, violations }: CreateTokenFailure): string[] {
    if (0 === violations.length) {
        return [message];
    }
    const fields: readonly string[] = FIELDS;

    return violations.filter(({ field }) => !fields.includes(field)).map((violation) => violation.message);
}
