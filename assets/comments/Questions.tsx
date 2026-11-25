import { Button, TextInput } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { type FormEvent, lazy, Suspense, useId } from 'react';
import { type SubmitTarget, useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import type { Question } from '../api/types/common';
import type { QuestionInput } from '../api/types/inputs';
import { useSessionDraft } from '../layout/useSessionDraft';
import { IssueKeyText } from './IssueKeyText';
import styles from './Questions.module.css';
import { useUnsavedComment } from './UnsavedCommentGuard';

const MarkdownBody = lazy(() => import('./MarkdownBody').then(({ MarkdownBody }) => ({ default: MarkdownBody })));

const ANSWER_MAX_CHARACTERS = 4000;

const CHOICES = ['Yes', 'No'] as const;

type Choice = (typeof CHOICES)[number];

type Answer = { choice: Choice | null; text: string };

const NO_ANSWER: Answer = { choice: null, text: '' };

type QuestionFormProps = {
    questions: Question[];
    /** The accessible name of the form. It says which comment the form answers. */
    label: string;
    /** The {@link useSessionDraft} key of the answers, as JSON. */
    draftKey: string;
    /** The JSON body for the action of the route, from every question with its answer, and with `draftKey`, so the write that succeeds removes the draft (D109). */
    intent(answers: QuestionInput[], draftKey: string): SubmitTarget;
};

/**
 * The open questions of an agent comment (D107, D124). "Send answers" submits every question with its answer, so the
 * answer comment reads on its own. A reload keeps the typed answers.
 */
export function QuestionForm({ questions, label, draftKey, intent }: QuestionFormProps) {
    const fetcher = useFetcher<ActionResult>();
    const [draft, setDraft] = useSessionDraft(draftKey);
    const id = useId();
    const busy = 'idle' !== fetcher.state;
    const error = busy ? null : (fetcher.data?.error ?? null);
    const answers = draftAnswers(draft, questions.length);
    const given = answers.map(givenAnswer);
    useUnsavedComment(!busy && given.some((answer) => '' !== answer), draftKey);

    const answer = (index: number, next: Answer): void =>
        setDraft((current) => {
            const changed = draftAnswers(current, questions.length).map((old, at) => (at === index ? next : old));

            return changed.some(({ choice, text }) => null !== choice || '' !== text) ? JSON.stringify(changed) : '';
        });

    const send = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        if (busy || given.includes('')) {
            return;
        }
        void fetcher.submit(intent(questions.map(({ question }, index) => ({ question, answer: given[index] ?? '' })), draftKey), { method: 'post', encType: 'application/json' });
    };

    return (
        <form className={styles.form} onSubmit={send} aria-label={label} data-testid="open-questions">
            {questions.map(({ question, detail }, index) => {
                const { choice, text } = answers[index] ?? NO_ANSWER;

                return (
                    <div key={index} role="group" aria-labelledby={`${id}-${index}`} className={styles.question}>
                        <p id={`${id}-${index}`} className={styles.title}>
                            <IssueKeyText text={question} />
                        </p>
                        {null !== detail && '' !== detail && (
                            <Suspense fallback={<p className={styles.plain} data-markdown-pending="">{detail}</p>}>
                                <MarkdownBody markdown={detail} />
                            </Suspense>
                        )}
                        <div className={styles.answer}>
                            {CHOICES.map((option) => (
                                <Button key={option} aria-pressed={choice === option} variant={choice === option ? 'primary' : 'default'} onClick={() => answer(index, { choice: option, text: '' })} disabled={busy}>
                                    {option}
                                </Button>
                            ))}
                            <TextInput
                                aria-label="Other answer"
                                placeholder="Other answer"
                                value={text}
                                onChange={(event) => answer(index, { choice: null, text: event.target.value })}
                                maxLength={ANSWER_MAX_CHARACTERS}
                                className={styles.text}
                                readOnly={busy}
                            />
                        </div>
                    </div>
                );
            })}
            {null !== error && (
                <InlineMessage variant="critical" role="alert" data-testid="answers-error">
                    {error}
                </InlineMessage>
            )}
            <div className={styles.actions}>
                <Button type="submit" variant="primary" disabled={given.includes('')} loading={busy}>
                    Send answers
                </Button>
            </div>
        </form>
    );
}

/** The questions with the answers of the {@link QuestionForm} draft `draft`, or null when it has no answer. */
export function withDraftAnswers(questions: Question[], draft: string): Question[] | null {
    const given = draftAnswers(draft, questions.length).map(givenAnswer);

    return given.some((answer) => '' !== answer) ? questions.map((question, index) => ({ ...question, answer: given[index] || null })) : null;
}

function givenAnswer({ choice, text }: Answer): string {
    return choice ?? text.trim();
}

/** A draft that is not the JSON of `count` answers gives no answer. */
function draftAnswers(draft: string, count: number): Answer[] {
    let stored: unknown = null;
    try {
        stored = JSON.parse(draft);
    } catch {}

    return Array.isArray(stored) && count === stored.length && stored.every(isAnswer) ? stored : Array.from({ length: count }, () => NO_ANSWER);
}

function isAnswer(value: unknown): value is Answer {
    return 'object' === typeof value && null !== value && 'choice' in value && 'text' in value && (null === value.choice || CHOICES.some((choice) => choice === value.choice)) && 'string' === typeof value.text;
}

/** The questions of a comment: an agent's with their detail, a user's with their answers. */
export function QuestionList({ questions }: { questions: Question[] }) {
    return (
        <ol className={styles.list} data-testid="comment-questions">
            {questions.map(({ question, detail, answer }, index) => (
                <li key={index} className={styles.item}>
                    <strong>
                        <IssueKeyText text={question} />
                    </strong>
                    {null !== answer ? (
                        <p className={styles.plain} data-testid="answer">
                            {answer}
                        </p>
                    ) : (
                        null !== detail &&
                        '' !== detail && (
                            <Suspense fallback={<p className={styles.plain} data-markdown-pending="">{detail}</p>}>
                                <MarkdownBody markdown={detail} />
                            </Suspense>
                        )
                    )}
                </li>
            ))}
        </ol>
    );
}
