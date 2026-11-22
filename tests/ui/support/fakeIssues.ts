import type { ApprovePlanInput, IssueCommentInput } from '../../../assets/api/types/inputs';
import type { Issue, IssueDetail, IssueEntry } from '../../../assets/api/types/issue';
import type { Project } from '../../../assets/api/types/project';
import { newId, now, project, USER } from './builders';
import { apiError, json, type MockApi, type MockResponse } from './mockApi';

/** The issue tracker in memory: P1 and I1 to I4, with the rules of docs/specs/rest-api-issues.md. */
export class FakeIssues {
    projects: Project[] = [project()];
    /** By issue key. */
    readonly issues = new Map<string, IssueDetail>();

    constructor(api: MockApi) {
        api.on('GET', '/api/v1/projects', () => json('lists.schema.json#/$defs/ProjectPage', { items: this.projects, nextCursor: null }));
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
                const { body: text } = body as IssueCommentInput;
                if ('' === text.trim()) {
                    return refusal('body', 'Must contain a non-whitespace character.');
                }

                return json('issue.schema.json', this.#write(detail, {}, entry('comment', { body: text })), 201);
            }),
        );
        api.on('POST', '/api/v1/issues/:issueKey/plan-approval', ({ params, body }) =>
            this.#answer(params.issueKey, (detail) => {
                const { planRevision } = body as ApprovePlanInput;
                const { issue } = detail;
                if (null === issue.planRevision) {
                    return refusal('plan', 'The issue has no plan. Write it with update_issue.');
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
    return { id: newId(), kind, actor: USER, createdAt: now(), body: null, section: null, from: null, to: null, note: null, reviewer: null, reviewNote: null, planRevision: null, ...fields };
}

function notFound(): MockResponse {
    return apiError(404, 'not_found', 'The resource does not exist.');
}

function refusal(field: string, message: string): MockResponse {
    return apiError(422, 'invalid_input', `Invalid input. ${field}: ${message}`, { violations: [{ field, message }] });
}
