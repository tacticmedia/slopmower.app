import { Banner, Dialog, FormControl, TextInput } from '@primer/react';
import { useId, useRef, useState } from 'react';
import { useFetcher } from 'react-router';
import { formMessages, type InputFailure } from '../../api/actionResult';
import { useSubmitOnce } from '../../layout/useSubmitOnce';
import formStyles from '../../settings/Settings.module.css';
import { ApprovalModeField } from './ApprovalModeField';

const FIELDS = ['key', 'name'] as const;

/** P2 through the action of the project list, which opens the new project. A refused input stays in the dialog. */
export function NewProjectDialog({ onClose }: { onClose: () => void }) {
    const fetcher = useFetcher<InputFailure>();
    const submitOnce = useSubmitOnce(fetcher.submit);
    const formId = useId();
    const keyInput = useRef<HTMLInputElement>(null);
    const [key, setKey] = useState('');
    const busy = 'idle' !== fetcher.state;
    const failure = fetcher.data ?? null;
    const fieldMessage = (field: (typeof FIELDS)[number]): string | undefined => failure?.violations.find((violation) => violation.field === field)?.message;
    const keyMessage = fieldMessage('key');
    const nameMessage = fieldMessage('name');
    const closeUnlessBusy = (): void => {
        if (!busy) {
            onClose();
        }
    };

    return (
        <Dialog
            title="New project"
            subtitle="A project holds the issues of its repositories."
            onClose={closeUnlessBusy}
            initialFocusRef={keyInput}
            footerButtons={[
                { content: 'Cancel', onClick: closeUnlessBusy, disabled: busy },
                { content: 'Create project', buttonType: 'primary', type: 'submit', form: formId, loading: busy },
            ]}
        >
            <fetcher.Form method="post" action="/projects" id={formId} onSubmit={submitOnce} className={formStyles.form}>
                {(null === failure ? [] : formMessages(failure, FIELDS)).map((message) => (
                    <Banner key={message} variant="critical" title="The project was not created" description={message} />
                ))}
                <FormControl required>
                    <FormControl.Label>Key</FormControl.Label>
                    <TextInput name="key" ref={keyInput} value={key} onChange={(event) => setKey(event.target.value.toUpperCase())} maxLength={10} autoComplete="off" monospace />
                    <FormControl.Caption>2 to 10 letters. Each issue key starts with it, for example BILL-12. It cannot change later.</FormControl.Caption>
                    {undefined !== keyMessage && <FormControl.Validation variant="error">{keyMessage}</FormControl.Validation>}
                </FormControl>
                <FormControl required>
                    <FormControl.Label>Name</FormControl.Label>
                    <TextInput name="name" block maxLength={255} autoComplete="off" />
                    {undefined !== nameMessage && <FormControl.Validation variant="error">{nameMessage}</FormControl.Validation>}
                </FormControl>
                <ApprovalModeField value="user" />
            </fetcher.Form>
        </Dialog>
    );
}
