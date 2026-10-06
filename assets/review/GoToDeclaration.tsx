import { ActionList, ActionMenu, TextInput } from '@primer/react';
import { type FormEvent, useRef } from 'react';
import type { Declaration } from '../api/types/declaration';
import type { GoToOutcome } from '../routes/reviews/declarationsData';
import styles from './GoToDeclaration.module.css';

type Props = {
    /** Of the last lookup, also of a click in the code. */
    outcome: GoToOutcome | null;
    onSubmit(name: string): void;
    onChoose(target: Declaration): void;
    onDismiss(): void;
};

/**
 * The keyboard path of code navigation. The menu of several targets also serves a click in the code.
 */
export function GoToDeclaration({ outcome, onSubmit, onChoose, onDismiss }: Props) {
    const form = useRef<HTMLFormElement>(null);
    const targets = null !== outcome && 'targets' in outcome ? outcome.targets : null;

    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        const name = String(new FormData(event.currentTarget).get('name') ?? '').trim();

        if ('' !== name) {
            onSubmit(name);
        }
    };

    return (
        <form ref={form} className={styles.form} onSubmit={submit}>
            <TextInput name="name" aria-label="Go to declaration" placeholder="Go to declaration" size="small" autoComplete="off" spellCheck={false} className={styles.field} />
            <span role="status" className={styles.message}>
                {null !== outcome && 'message' in outcome ? outcome.message : ''}
            </span>
            <ActionMenu anchorRef={form} open={null !== targets} onOpenChange={(open) => !open && onDismiss()}>
                <ActionMenu.Overlay width="large">
                    <ActionList aria-label="Declarations">
                        {targets?.map((target) => (
                            <ActionList.Item key={`${target.path}:${target.declarationStartLine}:${target.fqn}`} onSelect={() => onChoose(target)}>
                                {target.fqn}
                                <ActionList.Description variant="block">
                                    {target.path}, line {target.declarationStartLine}
                                </ActionList.Description>
                            </ActionList.Item>
                        ))}
                    </ActionList>
                </ActionMenu.Overlay>
            </ActionMenu>
        </form>
    );
}
