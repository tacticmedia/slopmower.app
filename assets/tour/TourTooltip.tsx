import { XIcon } from '@primer/octicons-react';
import { Button, Heading, IconButton, Link, Text } from '@primer/react';
import { createContext, useContext, useId } from 'react';
import type { TooltipRenderProps } from 'react-joyride';
import { Link as RouterLink } from 'react-router';
import styles from './TourTooltip.module.css';

/** `part` completes the current part, `tour` every part. */
export type TourEnd = 'part' | 'tour';

export const TourEndContext = createContext<(end: TourEnd) => void>(() => undefined);

/** The `data` of a step. */
export type TourStepData = { settingsLink: boolean };

/**
 * The tooltip of each step. Joyride moves the focus to `[data-action="primary"]` and back to the element that had it
 * before the tour.
 */
export function TourTooltip({ index, size, isLastStep, step, backProps, primaryProps, tooltipProps }: TooltipRenderProps) {
    const end = useContext(TourEndContext);
    const titleId = useId();
    const textId = useId();
    const { settingsLink } = step.data as TourStepData;

    return (
        <div {...tooltipProps} role="dialog" aria-labelledby={titleId} aria-describedby={textId} className={styles.tooltip} data-testid="review-tour">
            <div className={styles.header}>
                <Heading as="h2" id={titleId} variant="small">
                    {step.title}
                </Heading>
                <IconButton icon={XIcon} aria-label="Close" variant="invisible" size="small" onClick={() => end('part')} />
            </div>
            <Text as="p" id={textId} className={styles.text}>
                {step.content}
                {settingsLink && (
                    <>
                        {' '}
                        <Link as={RouterLink} to="/settings">
                            Create an API token in Settings
                        </Link>{' '}
                        first.
                    </>
                )}
            </Text>
            <div className={styles.footer}>
                <Text size="small" className={styles.progress}>
                    {index + 1} of {size}
                </Text>
                <Link as="button" type="button" className={styles.skip} onClick={() => end('tour')}>
                    Skip tour
                </Link>
                {index > 0 && (
                    <Button size="small" onClick={backProps.onClick}>
                        Back
                    </Button>
                )}
                <Button size="small" variant="primary" data-action={primaryProps['data-action']} onClick={isLastStep ? () => end('part') : primaryProps.onClick}>
                    {isLastStep ? 'Done' : 'Next'}
                </Button>
            </div>
        </div>
    );
}
