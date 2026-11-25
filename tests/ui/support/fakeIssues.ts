import { type ApprovalMode, ISSUE_SECTIONS, type IssueStatus, type Question } from '../../../assets/api/types/common';
import type { ApprovePlanInput, CreateIssueInput, CreateProjectInput, IssueCommentInput, IssueStatusInput, UpdateIssueInput, UpdateProjectInput } from '../../../assets/api/types/inputs';
import type { Issue, IssueDetail, IssueEntry, IssueSummary } from '../../../assets/api/types/issue';
import type { Project } from '../../../assets/api/types/project';
import type { Repository } from '../../../assets/api/types/repository';
import { issueDetail, newId, now, project, USER } from './builders';
import { apiError, json, type MockApi, type MockResponse } from './mockApi';

/** The sections that a status needs, in the order of the checks. */
const NEEDED: readonly { section: 'problem' | 'plan' | 'result'; statuses: readonly IssueStatus[] }[] = [
    { section: 'problem', statuses: ['backlog', 'todo', 'in_progress', 'in_review', 'done'] },
    { section: 'plan', statuses: ['in_progress', 'in_review', 'done'] },
    { section: 'result', statuses: ['in_review', 'done'] },
];

/** A move to one of these, or out of `in_progress`, ends the claim (D130). */
const PASSING_STATUSES: readonly IssueStatus[] = ['backlog', 'awaiting_user_input', 'in_review', 'done', 'wont_do'];

/** The statuses of an open issue that waits for the user: I9 holds such an issue without a dependency. */
const WAITS_FOR_USER: readonly IssueStatus[] = ['awaiting_user_input', 'in_review'];

/**
 * The issue tracker in memory, as a signed-in user sees it: P1 to P3, I1 to I9, and I11, with the rules of
 * docs/specs/rest-api-issues.md. `repositories` answers the connected repositories, whose IDs P3 takes.
 */
export class FakeIssues {
    projects: Project[] = [project()];
    /** By issue key. */
    readonly issues = new Map<string, IssueDetail>();

    constructor(api: MockApi, repositories: () => Repository[] = () => []) {
        api.on('GET', '/api/v1/projects', () => json('lists.schema.json#/$defs/ProjectPage', { items: this.projects, nextCursor: null }));
        api.on('POST', '/api/v1/projects', ({ body }) => {
            const { key, name, approvalMode, approvalGuidelines = null } = body as CreateProjectInput;
            if (!/^[A-Z]{2,10}$/.test(key)) {
                return refusal('key', 'Must have 2 to 10 upper-case letters.');
            }
            if (this.projects.some((known) => known.key === key)) {
                return refusal('key', `The project ${key} exists already.`);
            }
            const created: Project = { key, name: name.trim(), approvalMode, approvalGuidelines: guidelines(approvalGuidelines), repositories: [] };
            this.projects = [...this.projects, created];

            return json('project.schema.json', created, 201);
        });
        api.on('PATCH', '/api/v1/projects/:projectKey', ({ params, body }) => {
            const { name, approvalMode, approvalGuidelines, repositoryIds } = body as UpdateProjectInput;
            const stored = this.projects.find(({ key }) => key === params.projectKey);
            const members = (repositoryIds ?? []).map((id) => repositories().find((known) => known.id === id)?.name);
            if (undefined === stored || members.includes(undefined)) {
                return notFound();
            }
            for (const member of members) {
                const owner = this.projects.find((known) => known !== stored && known.repositories.includes(member ?? ''));
                if (undefined !== owner) {
                    return refusal('repositoryIds', `The repository ${member} belongs to the project ${owner.name}.`);
                }
            }
            const changed: Project = {
                ...stored,
                name: name?.trim() ?? stored.name,
                approvalMode: approvalMode ?? stored.approvalMode,
                approvalGuidelines: null === approvalGuidelines || undefined === approvalGuidelines ? stored.approvalGuidelines : guidelines(approvalGuidelines),
                repositories: null === repositoryIds || undefined === repositoryIds ? stored.repositories : [...new Set(members as string[])].toSorted(),
            };
            this.projects = this.projects.map((known) => (known === stored ? changed : known));

            return json('project.schema.json', changed);
        });
        api.on('POST', '/api/v1/projects/:projectKey/issues', ({ params, body }) => {
            const { title, category, problem = null, status, approval = null } = body as CreateIssueInput;
            const owner = this.projects.find(({ key }) => key === params.projectKey);
            if (undefined === owner) {
                return notFound();
            }
            if (undefined === category) {
                return refusal('category', 'This value should not be null.');
            }
            if (null !== approval && 'by_issue' !== owner.approvalMode) {
                return approvalRefusal(owner.approvalMode);
            }
            const numbers = [...this.issues.keys()].filter((key) => key.startsWith(`${owner.key}-`)).map((key) => Number(key.slice(owner.key.length + 1)));
            const created: Issue = {
                ...issueDetail().issue,
                key: `${owner.key}-${Math.max(0, ...numbers) + 1}`,
                title: title.trim(),
                category,
                status: status ?? 'draft',
                approval: 'by_issue' === owner.approvalMode ? (approval ?? 'user') : owner.approvalMode,
                problem,
                plan: null,
                project: { key: owner.key, name: owner.name, approvalMode: owner.approvalMode },
                planRevision: null,
                planApproval: null,
                claim: null,
                createdBy: USER,
                createdAt: now(),
                updatedAt: now(),
            };
            const refused = missingSection(created);
            if (undefined !== refused) {
                return refused;
            }
            this.issues.set(created.key, { issue: created, links: [], thread: null === problem ? [] : [entry('section_changed', { section: 'problem', body: problem })] });

            return json('issue.schema.json', created, 201);
        });
        api.on('PATCH', '/api/v1/issues/:issueKey', ({ params, body }) =>
            this.#answer(params.issueKey, (detail) => {
                const input = body as UpdateIssueInput;
                const changes: Partial<Issue> = { ...(null === input.title || undefined === input.title ? {} : { title: input.title.trim() }), ...(null === input.category || undefined === input.category ? {} : { category: input.category }) };
                if (null !== input.approval && undefined !== input.approval) {
                    if ('by_issue' !== detail.issue.project.approvalMode) {
                        return approvalRefusal(detail.issue.project.approvalMode);
                    }
                    changes.approval = input.approval;
                }
                const entries: IssueEntry[] = [];
                for (const section of ISSUE_SECTIONS) {
                    const text = input[section];
                    if (null !== text && undefined !== text && text !== detail.issue[section]) {
                        const changed = entry('section_changed', { section, body: text });
                        entries.push(changed);
                        Object.assign(changes, { [section]: text }, 'plan' === section ? { planRevision: changed.id, planApproval: null } : {});
                    }
                }

                // A change of the category alone keeps updatedAt (D133).
                const categoryOnly = 0 === entries.length && Object.keys(changes).every((field) => 'category' === field);

                return missingSection({ ...detail.issue, ...changes }) ?? json('issue.schema.json', this.#write(detail, categoryOnly ? { ...changes, updatedAt: detail.issue.updatedAt } : changes, ...entries));
            }),
        );
        api.on('POST', '/api/v1/issues/:issueKey/status', ({ params, body }) =>
            this.#answer(params.issueKey, (detail) => {
                const { status, note = null } = body as IssueStatusInput;
                const { issue } = detail;
                if ('wont_do' === status && '' === (note ?? '').trim()) {
                    return refusal('note', 'wont_do needs a note that says why.');
                }

                const dismissals = 'wont_do' === status ? detail.thread.filter(isOpenQuestion).map((asked) => dismissal(asked, "Dismissed: the issue is won't do.")) : [];

                return (
                    missingSection({ ...issue, status }) ??
                    json(
                        'issue.schema.json',
                        status === issue.status
                            ? issue
                            : this.#write(detail, { status, claim: 'in_progress' === issue.status || PASSING_STATUSES.includes(status) ? null : issue.claim }, entry('status_changed', { from: issue.status, to: status, note }), ...dismissals),
                    )
                );
            }),
        );
        api.on('GET', '/api/v1/projects/:projectKey/issues', ({ params, query }) => {
            if (!this.projects.some(({ key }) => key === params.projectKey)) {
                return notFound();
            }
            const [status, category] = [query.get('status'), query.get('category')];
            const items = [...this.issues.values()]
                .map(({ issue }) => issue)
                .filter((issue) => issue.project.key === params.projectKey && (null === status || issue.status === status) && (null === category || issue.category === category))
                .toSorted((first, second) => second.updatedAt.localeCompare(first.updatedAt))
                .map(summary);

            return json('lists.schema.json#/$defs/IssuePage', { items, nextCursor: null });
        });
        api.on('GET', '/api/v1/issues/:issueKey', ({ params, query }) =>
            this.#answer(params.issueKey, (detail) => json('issue.schema.json#/$defs/IssueDetail', 'true' === query.get('history') ? detail : { ...detail, thread: currentContent(detail.thread) })),
        );
        api.on('POST', '/api/v1/issues/:issueKey/comments', ({ params, body }) =>
            this.#answer(params.issueKey, (detail) => {
                const { body: text, label, replaces = null, questions = [], dismiss = false } = body as IssueCommentInput;
                if (0 === questions.length && !dismiss) {
                    return '' === text.trim()
                        ? refusal('body', 'Must contain a non-whitespace character.')
                        : json('issue.schema.json', this.#write(detail, {}, entry('comment', { body: text, label: label ?? 'comment', replaces })), 201);
                }
                const open = detail.thread.find((known) => known.id === replaces && isOpenQuestion(known));
                if (undefined === open) {
                    return refusal('replaces', 'Is no open question comment.');
                }
                if (dismiss) {
                    return json('issue.schema.json', this.#write(detail, {}, dismissal(open, '' === text.trim() ? 'Dismissed.' : text)), 201);
                }
                const answers = open.questions.map((question, index) => ({ ...question, answer: questions[index]?.answer ?? null }));

                return json('issue.schema.json', this.#write(detail, {}, entry('comment', { body: text, label: 'answer', replaces, questions: answers })), 201);
            }),
        );
        api.on('POST', '/api/v1/issues/:issueKey/plan-approval', ({ params, body }) =>
            this.#answer(params.issueKey, (detail) => {
                const { planRevision } = body as ApprovePlanInput;
                const { issue } = detail;
                if (null === issue.planRevision) {
                    return refusal('plan', 'The issue has no plan.');
                }
                if (planRevision !== issue.planRevision) {
                    return refusal('planRevision', 'The plan changed after this revision. Read the plan again, and approve its newest revision.');
                }
                const approved = entry('plan_approved', { planRevision });
                const toTodo = 'awaiting_user_input' === issue.status && '' !== (issue.problem ?? '').trim();
                const changes: Partial<Issue> = { planApproval: { approvedBy: USER, approvedAt: approved.createdAt, reviewer: null, reviewNote: null }, ...(toTodo ? { status: 'todo' } : {}) };

                return json('issue.schema.json', this.#write(detail, changes, approved, ...(toTodo ? [entry('status_changed', { from: issue.status, to: 'todo' })] : [])));
            }),
        );
        api.on('POST', '/api/v1/issues/:issueKey/release', ({ params }) =>
            this.#answer(params.issueKey, (detail) => {
                const issue = { ...detail.issue, claim: null };
                this.issues.set(issue.key, { ...detail, issue });

                return json('issue.schema.json', issue);
            }),
        );
        api.on('GET', '/api/v1/projects/:projectKey/comments', ({ params, query }) => {
            if (!this.projects.some(({ key }) => key === params.projectKey)) {
                return notFound();
            }
            const label = query.get('label');
            const items = [...this.issues.values()]
                .filter(({ issue }) => issue.project.key === params.projectKey)
                .flatMap(({ issue, thread }) =>
                    thread.flatMap(({ id, label: entryLabel, actor, createdAt, body, questions, replacedBy }) =>
                        null === entryLabel || null === body || null !== replacedBy || (null !== label && entryLabel !== label)
                            ? []
                            : [{ id, issue: summary(issue), label: entryLabel, actor, createdAt, body, questions }],
                    ),
                )
                .toSorted((first, second) => second.id.localeCompare(first.id));

            return json('lists.schema.json#/$defs/IssueCommentPage', { items, nextCursor: null });
        });
        api.on('GET', '/api/v1/projects/:projectKey/graph', ({ params }) => {
            if (!this.projects.some(({ key }) => key === params.projectKey)) {
                return notFound();
            }
            const open = new Map([...this.issues.values()].filter(({ issue }) => !['done', 'wont_do'].includes(issue.status)).map(({ issue }) => [issue.key, issue]));
            const ofProject = [...open.values()].filter(({ project }) => project.key === params.projectKey);
            const links = [...this.issues.values()]
                .flatMap(({ issue, links: ofIssue }) => ofIssue.filter(({ kind }) => 'blocked_by' === kind).map((link) => ({ issue: issue.key, blockedBy: link.issue.key })))
                .filter(({ issue, blockedBy }) => open.has(issue) && open.has(blockedBy) && ofProject.some(({ key }) => key === issue || key === blockedBy))
                .toSorted((first, second) => compareKeys(first.issue, second.issue) || compareKeys(first.blockedBy, second.blockedBy));
            const keys = new Set([...ofProject.filter(({ status }) => WAITS_FOR_USER.includes(status)).map(({ key }) => key), ...links.flatMap(({ issue, blockedBy }) => [issue, blockedBy])]);
            const issues = [...keys].toSorted(compareKeys).map((key) => summary(open.get(key) as Issue));

            return json('issue.schema.json#/$defs/IssueGraph', { issues, links, otherOpenIssues: ofProject.filter(({ key }) => !keys.has(key)).length });
        });
    }

    add(detail: IssueDetail): IssueDetail {
        this.issues.set(detail.issue.key, detail);

        return detail;
    }

    /** As an agent with `link_issues` and `kind: blocked_by`: `issueKey` waits for `blockerKey`. Both issues show the link. */
    block(issueKey: string, blockerKey: string): void {
        const [waiting, blocker] = [this.#stored(issueKey), this.#stored(blockerKey)];
        const link = { note: `${blockerKey} comes first.`, createdBy: waiting.issue.createdBy, createdAt: now() };
        this.issues.set(issueKey, { ...waiting, links: [...waiting.links, { ...link, issue: summary(blocker.issue), kind: 'blocked_by' }] });
        this.issues.set(blockerKey, { ...blocker, links: [...blocker.links, { ...link, issue: summary(waiting.issue), kind: 'blocks' }] });
    }

    /** As an agent with `update_issue`: a new plan revision without an approval. */
    changePlan(issueKey: string, plan: string): void {
        const detail = this.#stored(issueKey);
        const changed = { ...entry('section_changed', { section: 'plan', body: plan }), actor: detail.issue.createdBy };
        this.#write(detail, { plan, planRevision: changed.id, planApproval: null }, changed);
    }

    /** As an agent with `comment_on_issue`. */
    agentComments(issueKey: string, body: string): void {
        const detail = this.#stored(issueKey);
        this.#write(detail, {}, { ...entry('comment', { body, label: 'comment' }), actor: detail.issue.createdBy });
    }

    /** As an agent with `comment_on_issue` and `questions`: an open question comment, which replaces the comment `replaces`. */
    agentAsks(issueKey: string, body: string, questions: Question[], replaces: string | null = null): IssueEntry {
        const detail = this.#stored(issueKey);
        const asked = { ...entry('comment', { body, label: 'question', questions, replaces }), actor: detail.issue.createdBy };
        this.#write(detail, {}, asked);

        return asked;
    }

    #stored(issueKey: string): IssueDetail {
        const detail = this.issues.get(issueKey);
        if (undefined === detail) {
            throw new Error(`No issue ${issueKey}.`);
        }

        return detail;
    }

    #answer(issueKey: string | undefined, respond: (detail: IssueDetail) => MockResponse): MockResponse {
        const detail = this.issues.get(issueKey ?? '');

        return undefined === detail ? notFound() : respond(detail);
    }

    #write(detail: IssueDetail, changes: Partial<Issue>, ...entries: IssueEntry[]): Issue {
        const issue = { ...detail.issue, updatedAt: now(), ...changes };
        this.issues.set(issue.key, { ...detail, issue, thread: versioned([...detail.thread, ...entries]) });

        return issue;
    }
}

function summary({ key, title, category, status, approval, claim, updatedAt }: Issue): IssueSummary {
    return { key, title, category, status, approval, claim, updatedAt };
}

/** P2 and P3 store whitespace only as null. */
function guidelines(text: string | null): string | null {
    return '' === (text ?? '').trim() ? null : text;
}

function approvalRefusal(mode: ApprovalMode): MockResponse {
    return refusal('approval', `The approval mode of the project is ${mode}. Only a project in by_issue takes an approval per issue.`);
}

/** By project key, then by number. */
function compareKeys(first: string, second: string): number {
    const [firstProject = '', firstNumber] = first.split('-');
    const [secondProject = '', secondNumber] = second.split('-');

    return firstProject.localeCompare(secondProject) || Number(firstNumber) - Number(secondNumber);
}

/** An entry of the signed-in user. */
function entry(kind: IssueEntry['kind'], fields: Partial<IssueEntry>): IssueEntry {
    return {
        id: newId(),
        kind,
        label: null,
        actor: USER,
        createdAt: now(),
        body: null,
        questions: [],
        section: null,
        from: null,
        to: null,
        note: null,
        reviewer: null,
        reviewNote: null,
        planRevision: null,
        replaces: null,
        replacedBy: null,
        ...fields,
    };
}

function isOpenQuestion({ label, questions, replacedBy }: IssueEntry): boolean {
    return 'question' === label && questions.length > 0 && null === replacedBy;
}

/** The comment of the user that dismisses the open question comment `asked` (D124). */
function dismissal({ id, questions }: IssueEntry, body: string): IssueEntry {
    return entry('comment', { body, label: 'dismissal', replaces: id, questions });
}

/** `replacedBy` of the comments, and `replaces` and `replacedBy` of the section versions and the plan approvals, as the server derives them. */
function versioned(thread: IssueEntry[]): IssueEntry[] {
    const entries = thread.map((known) => ({ ...known }));
    const newest = new Map<string, IssueEntry>();
    for (const comment of entries.filter(({ kind, replaces }) => 'comment' === kind && null !== replaces)) {
        const replaced = entries.find(({ id }) => id === comment.replaces);
        if (undefined !== replaced) {
            replaced.replacedBy = comment.id;
        }
    }
    for (const version of entries.filter(({ section }) => null !== section)) {
        const before = newest.get(version.section ?? '');
        if (undefined !== before) {
            before.replacedBy = version.id;
            version.replaces = before.id;
        }
        newest.set(version.section ?? '', version);
    }
    for (const approval of entries.filter(({ kind }) => 'plan_approved' === kind)) {
        approval.replacedBy = entries.find(({ id }) => id === approval.planRevision)?.replacedBy ?? null;
    }

    return entries;
}

/** I2 without `history`: no replaced entry, and no text in a section version. */
function currentContent(thread: IssueEntry[]): IssueEntry[] {
    return thread.filter(({ replacedBy }) => null === replacedBy).map((known) => (null === known.section ? known : { ...known, body: null }));
}

function notFound(): MockResponse {
    return apiError(404, 'not_found', 'The resource does not exist.');
}

/** The refusal of the first section that the status of the issue needs and that is empty. */
function missingSection(issue: Issue): MockResponse | undefined {
    const missing = NEEDED.find(({ section, statuses }) => statuses.includes(issue.status) && '' === (issue[section] ?? '').trim())?.section;

    return undefined === missing ? undefined : refusal(missing, `An issue in ${issue.status} needs a ${missing}.`);
}

function refusal(field: string, message: string): MockResponse {
    return apiError(422, 'invalid_input', `Invalid input. ${field}: ${message}`, { violations: [{ field, message }] });
}
