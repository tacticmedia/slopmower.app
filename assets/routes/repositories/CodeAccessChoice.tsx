import { FormControl, Radio, RadioGroup } from '@primer/react';
import { CODE_ACCESSES, type CodeAccess } from '../../api/types/common';
import { HelpLink } from '../../layout/HelpLink';
import { HELP } from '../../layout/helpLinks';
import styles from './CodeAccess.module.css';

export const CODE_ACCESS_NAMES: Record<CodeAccess, string> = { clone: 'Server copy', pass_through: 'Pass-through' };

const SUMMARIES: Record<CodeAccess, string> = {
    clone: 'Slopmower keeps a copy of the repository. Fastest; works during a GitHub outage.',
    pass_through: 'Slopmower reads the code from GitHub on each request and stores none.',
};

type Props = {
    value: CodeAccess | null;
    onChange: (codeAccess: CodeAccess) => void;
};

/** The mode of the next GitHub connect (D111). Nothing is preselected, and Connect waits for a choice. */
export function CodeAccessChoice({ value, onChange }: Props) {
    return (
        <div className={styles.choice}>
            <RadioGroup name="codeAccess" onChange={(selected) => onChange(selected as CodeAccess)}>
                <RadioGroup.Label>Code access</RadioGroup.Label>
                <RadioGroup.Caption>
                    <HelpLink href={HELP.codeAccess}>Compare modes</HelpLink>
                </RadioGroup.Caption>
                {CODE_ACCESSES.map((mode) => (
                    <FormControl key={mode}>
                        <Radio value={mode} checked={mode === value} />
                        <FormControl.Label>{CODE_ACCESS_NAMES[mode]}</FormControl.Label>
                        <FormControl.Caption>{SUMMARIES[mode]}</FormControl.Caption>
                    </FormControl>
                ))}
            </RadioGroup>
        </div>
    );
}
