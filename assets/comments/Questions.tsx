import { Button, TextInput } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { type FormEvent, lazy, Suspense, useId, useState } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import type { Question, Ulid } from '../api/types/common';
import type { CommentIntent } from './commentIntent';
import styles from './Questions.module.css';
import { useUnsavedComment } from './UnsavedCommentGuard';

const MarkdownBody = lazy(() => import('./MarkdownBody').then(({ MarkdownBody }) => ({ default: MarkdownBody })));

const ANSWER_MAX_CHARACTERS = 4000;

type Choice = 'Yes' | 'No';

type Answer = { choice: Choice | null; text: string };

const NO_ANSWER: Answer = { choice: null, text: '' };

/**
 * The open questions of an agent comment (D107). "Send answers" sends Q4 with an empty body and every question with
 * its answer, so the answer comment reads on its own.
 */
export function QuestionForm({ requestId, questions }: { requestId: Ulid; questions: Question[] }) {
    const fetcher = useFetcher<ActionResult>();
    const [answers, setAnswers] = useState<Answer[]>(() => questions.map(() => NO_ANSWER));
    const id = useId();
    const busy = 'idle' !== fetcher.state;
    const error = busy ? null : (fetcher.data?.error ?? null);
    const given = answers.map(({ choice, text }) => choice ?? text.trim());
    useUnsavedComment(!busy && given.some((answer) => '' !== answer));

    const answer = (index: number, next: Answer): void => setAnswers((current) => current.map((old, at) => (at === index ? next : old)));

    const send = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        if (busy || given.includes('')) {
            return;
        }
        const intent: CommentIntent = { intent: 'reply', requestId, input: { body: '', questions: questions.map(({ question }, index) => ({ question, answer: given[index] ?? '' })) } };
        void fetcher.submit(intent, { method: 'post', encType: 'application/json' });
    };

    return (
        <form className={styles.form} onSubmit={send} aria-label="Questions of the agent" data-testid="open-questions">
            {questions.map(({ question, detail }, index) => {
                const { choice, text } = answers[index] ?? NO_ANSWER;

                return (
                    <div key={index} role="group" aria-labelledby={`${id}-${index}`} className={styles.question}>
                        <p id={`${id}-${index}`} className={styles.title}>
                            {question}
                        </p>
                        {null !== detail && '' !== detail && (
                            <Suspense fallback={<p className={styles.plain}>{detail}</p>}>
                                <MarkdownBody markdown={detail} />
                            </Suspense>
                        )}
                        <div className={styles.answer}>
                            {(['Yes', 'No'] as const).map((option) => (
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

/** The questions of a comment: an agent's with their detail, a user's with their answers. */
export function QuestionList({ questions }: { questions: Question[] }) {
    return (
        <ol className={styles.list} data-testid="comment-questions">
            {questions.map(({ question, detail, answer }, index) => (
                <li key={index} className={styles.item}>
                    <strong>{question}</strong>
                    {null !== answer ? (
                        <p className={styles.plain} data-testid="answer">
                            {answer}
                        </p>
                    ) : (
                        null !== detail &&
                        '' !== detail && (
                            <Suspense fallback={<p className={styles.plain}>{detail}</p>}>
                                <MarkdownBody markdown={detail} />
                            </Suspense>
                        )
                    )}
                </li>
            ))}
        </ol>
    );
}
