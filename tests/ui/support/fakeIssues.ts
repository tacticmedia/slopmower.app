import { ISSUE_SECTIONS, type IssueStatus } from '../../../assets/api/types/common';
import type { ApprovePlanInput, CreateIssueInput, CreateProjectInput, IssueCommentInput, IssueStatusInput, UpdateIssueInput, UpdateProjectInput } from '../../../assets/api/types/inputs';
import type { Issue, IssueDetail, IssueEntry } from '../../../assets/api/types/issue';
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

/**
 * The issue tracker in memory, as a signed-in user sees it: P1 to P3 and I1 to I8, with the rules of
 * docs/specs/rest-api-issues.md. `repositories` answers the connected repositories, whose IDs P3 takes.
 */
export class FakeIssues {
    projects: Project[] = [project()];
    /** By issue key. */
    readonly issues = new Map<string, IssueDetail>();

    constructor(api: MockApi, repositories: () => Repository[] = () => []) {
        api.on('GET', '/api/v1/projects', () => json('lists.schema.json#/$defs/ProjectPage', { items: this.projects, nextCursor: null }));
        api.on('POST', '/api/v1/projects', ({ body }) => {
            const { key, name, approvalMode } = body as CreateProjectInput;
            if (!/^[A-Z]{2,10}$/.test(key)) {
                return refusal('key', 'Must have 2 to 10 upper-case letters.');
            }
            if (this.projects.some((known) => known.key === key)) {
                return refusal('key', `The project ${key} exists already.`);
            }
            const created: Project = { key, name: name.trim(), approvalMode, repositories: [] };
            this.projects = [...this.projects, created];

            return json('project.schema.json', created, 201);
        });
        api.on('PATCH', '/api/v1/projects/:projectKey', ({ params, body }) => {
            const { name, approvalMode, repositoryIds } = body as UpdateProjectInput;
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
                repositories: null === repositoryIds || undefined === repositoryIds ? stored.repositories : [...new Set(members as string[])].toSorted(),
            };
            this.projects = this.projects.map((known) => (known === stored ? changed : known));

            return json('project.schema.json', changed);
        });
        api.on('POST', '/api/v1/projects/:projectKey/issues', ({ params, body }) => {
            const { title, problem = null, status } = body as CreateIssueInput;
            const owner = this.projects.find(({ key }) => key === params.projectKey);
            if (undefined === owner) {
                return notFound();
            }
            const numbers = [...this.issues.keys()].filter((key) => key.startsWith(`${owner.key}-`)).map((key) => Number(key.slice(owner.key.length + 1)));
            const created: Issue = {
                ...issueDetail().issue,
                key: `${owner.key}-${Math.max(0, ...numbers) + 1}`,
                title: title.trim(),
                status: status ?? 'draft',
                problem,
                plan: null,
                project: { key: owner.key, name: owner.name, approvalMode: owner.approvalMode },
                planRevision: null,
                planApproval: null,
                createdBy: USER,
                createdAt: now(),
                updatedAt: now(),
            };
            const refused = missingSection(created);
            if (undefined !== refused) {
                return refused;
            }
            this.issues.set(created.key, { issue: created, links: [], thread: null === problem ? [] : [entry('section_changed', { section: 'problem' })] });

            return json('issue.schema.json', created, 201);
        });
        api.on('PATCH', '/api/v1/issues/:issueKey', ({ params, body }) =>
            this.#answer(params.issueKey, (detail) => {
                const input = body as UpdateIssueInput;
                const changes: Partial<Issue> = null === input.title || undefined === input.title ? {} : { title: input.title.trim() };
                const entries: IssueEntry[] = [];
                for (const section of ISSUE_SECTIONS) {
                    const text = input[section];
                    if (null !== text && undefined !== text && text !== detail.issue[section]) {
                        const changed = entry('section_changed', { section });
                        entries.push(changed);
                        Object.assign(changes, { [section]: text }, 'plan' === section ? { planRevision: changed.id, planApproval: null } : {});
                    }
                }

                return missingSection({ ...detail.issue, ...changes }) ?? json('issue.schema.json', this.#write(detail, changes, ...entries));
            }),
        );
        api.on('POST', '/api/v1/issues/:issueKey/status', ({ params, body }) =>
            this.#answer(params.issueKey, (detail) => {
                const { status, note = null } = body as IssueStatusInput;
                const { issue } = detail;
                if ('wont_do' === status && '' === (note ?? '').trim()) {
                    return refusal('note', 'wont_do needs a note that says why.');
                }

                return (
                    missingSection({ ...issue, status }) ??
                    json('issue.schema.json', status === issue.status ? issue : this.#write(detail, { status }, entry('status_changed', { from: issue.status, to: status, note })))
                );
            }),
        );
        api.on('GET', '/api/v1/projects/:projectKey/issues', ({ params, query }) => {
            if (!this.projects.some(({ key }) => key === params.projectKey)) {
                return notFound();
            }
            const status = query.get('status');
            const items = [...this.issues.values()]
                .map(({ issue }) => issue)
                .filter((issue) => issue.project.key === params.projectKey && (null === status || issue.status === status))
                .toSorted((first, second) => second.updatedAt.localeCompare(first.updatedAt))
                .map(({ key, title, status: issueStatus, updatedAt }) => ({ key, title, status: issueStatus, updatedAt }));

            return json('lists.schema.json#/$defs/IssuePage', { items, nextCursor: null });
        });
        api.on('GET', '/api/v1/issues/:issueKey', ({ params }) => this.#answer(params.issueKey, (detail) => json('issue.schema.json#/$defs/IssueDetail', detail)));
        api.on('POST', '/api/v1/issues/:issueKey/comments', ({ params, body }) =>
            this.#answer(params.issueKey, (detail) => {
                const { body: text, label } = body as IssueCommentInput;
                if ('' === text.trim()) {
                    return refusal('body', 'Must contain a non-whitespace character.');
                }

                return json('issue.schema.json', this.#write(detail, {}, entry('comment', { body: text, label: label ?? 'comment' })), 201);
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
        api.on('GET', '/api/v1/projects/:projectKey/comments', ({ params, query }) => {
            if (!this.projects.some(({ key }) => key === params.projectKey)) {
                return notFound();
            }
            const label = query.get('label');
            const items = [...this.issues.values()]
                .filter(({ issue }) => issue.project.key === params.projectKey)
                .flatMap(({ issue: { key, title, status, updatedAt }, thread }) =>
                    thread.flatMap(({ id, label: entryLabel, actor, createdAt, body }) =>
                        null === entryLabel || null === body || (null !== label && entryLabel !== label) ? [] : [{ id, issue: { key, title, status, updatedAt }, label: entryLabel, actor, createdAt, body }],
                    ),
                )
                .toSorted((first, second) => second.id.localeCompare(first.id));

            return json('lists.schema.json#/$defs/IssueCommentPage', { items, nextCursor: null });
        });
    }

    add(detail: IssueDetail): IssueDetail {
        this.issues.set(detail.issue.key, detail);

        return detail;
    }

    /** As an agent with `update_issue`: a new plan revision without an approval. */
    changePlan(issueKey: string, plan: string): void {
        const detail = this.issues.get(issueKey);
        if (undefined === detail) {
            throw new Error(`No issue ${issueKey}.`);
        }
        const changed = { ...entry('section_changed', { section: 'plan' }), actor: detail.issue.createdBy };
        this.#write(detail, { plan, planRevision: changed.id, planApproval: null }, changed);
    }

    #answer(issueKey: string | undefined, respond: (detail: IssueDetail) => MockResponse): MockResponse {
        const detail = this.issues.get(issueKey ?? '');

        return undefined === detail ? notFound() : respond(detail);
    }

    #write(detail: IssueDetail, changes: Partial<Issue>, ...entries: IssueEntry[]): Issue {
        const issue = { ...detail.issue, ...changes, updatedAt: now() };
        this.issues.set(issue.key, { ...detail, issue, thread: [...detail.thread, ...entries] });

        return issue;
    }
}

/** An entry of the signed-in user. */
function entry(kind: IssueEntry['kind'], fields: Partial<IssueEntry>): IssueEntry {
    return { id: newId(), kind, label: null, actor: USER, createdAt: now(), body: null, section: null, from: null, to: null, note: null, reviewer: null, reviewNote: null, planRevision: null, ...fields };
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
