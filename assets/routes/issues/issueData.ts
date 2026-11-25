import { type ActionFunctionArgs, type LoaderFunctionArgs, type Params, redirect } from 'react-router';
import { actionError, type ActionResult, type InputFailure, inputFailure, refusalReasons, writeResult } from '../../api/actionResult';
import { apiGet, apiGetAllPages, apiWrite } from '../../api/client';
import { type ApprovalMode, COMMENT_LABELS, type CommentLabel, ISSUE_STATUSES, type IssueStatus } from '../../api/types/common';
import type { ApprovePlanInput, CreateIssueInput, CreateProjectInput, IssueCommentInput, IssueStatusInput, UpdateIssueInput, UpdateProjectInput } from '../../api/types/inputs';
import type { Issue, IssueComment, IssueDetail, IssueGraph, IssueSummary } from '../../api/types/issue';
import type { NextCursor, ProjectPage } from '../../api/types/lists';
import type { Project } from '../../api/types/project';
import type { Repository } from '../../api/types/repository';
import { removeSessionDraft } from '../../layout/useSessionDraft';
import { encodedRouteParam } from '../encodedRouteParam';
import { allRepositories } from '../repositories/RepositoriesRoute';

/** The JSON body of the writes of the issue page. The action removes the session draft `draftKey` after the write (D109). */
export type IssueIntent =
    | { intent: 'comment'; input: IssueCommentInput; draftKey?: string }
    | { intent: 'approve_plan'; input: ApprovePlanInput }
    | { intent: 'update'; input: UpdateIssueInput; draftKey?: string }
    | { intent: 'set_status'; input: IssueStatusInput; draftKey?: string }
    | { intent: 'release'; input: null };

/** The JSON body of the form of a new issue. */
export type NewIssueIntent = { input: CreateIssueInput; draftKey: string };

export type ProjectData = {
    project: Project;
    issues: IssueSummary[];
};

export type ProjectCommentsData = {
    project: Project;
    comments: IssueComment[];
};

export type ProjectGraphData = {
    project: Project;
    graph: IssueGraph;
};

/** P1 has the repository names of each project, and R1 has the IDs: a repository name is unique in a tenant. */
export type ProjectSettingsData = {
    project: Project;
    projects: Project[];
    repositories: Repository[];
};

async function allProjects(signal: AbortSignal): Promise<Project[]> {
    return (await apiGetAllPages<ProjectPage>('/api/v1/projects?limit=500', signal)).items;
}

export function projectsLoader({ request }: LoaderFunctionArgs): Promise<Project[]> {
    return allProjects(request.signal);
}

/** P2. The page becomes the new project. A refused input goes back to the dialog, for its fields. */
export async function projectsAction({ request }: ActionFunctionArgs): Promise<InputFailure | Response> {
    const form = await request.formData();
    const input: CreateProjectInput = { key: String(form.get('key')), name: String(form.get('name')), approvalMode: String(form.get('approvalMode')) as ApprovalMode };

    try {
        return redirect(`/projects/${(await apiWrite<Project>('POST', '/api/v1/projects', input, request.signal)).key}`);
    } catch (error) {
        return inputFailure(error);
    }
}

function projectOf(projects: Project[], params: Params): Project {
    const project = projects.find((known) => known.key === params.projectKey);
    if (undefined === project) {
        throw new Error(`P1 does not list the project ${params.projectKey}.`);
    }

    return project;
}

/** No endpoint answers one project, so its name comes from P1. */
export async function newIssueLoader({ request, params }: LoaderFunctionArgs): Promise<Project> {
    return projectOf(await allProjects(request.signal), params);
}

/** I6. The page becomes the new issue. */
export async function newIssueAction({ request, params }: ActionFunctionArgs): Promise<ActionResult | Response> {
    const { input, draftKey } = (await request.json()) as NewIssueIntent;

    try {
        const issue = await apiWrite<Issue>('POST', `/api/v1/projects/${encodedRouteParam(params, 'projectKey')}/issues`, input, request.signal);
        removeSessionDraft(draftKey);

        return redirect(`/issues/${issue.key}`);
    } catch (error) {
        return actionError(error);
    }
}

export async function projectSettingsLoader({ request, params }: LoaderFunctionArgs): Promise<ProjectSettingsData> {
    const [projects, repositories] = await Promise.all([allProjects(request.signal), allRepositories(request.signal)]);

    return { project: projectOf(projects, params), projects, repositories };
}

/** P3 with the whole form: the name, the mode, the guidelines while they show, and the checked repositories. */
export async function projectSettingsAction({ request, params }: ActionFunctionArgs): Promise<ActionResult> {
    const form = await request.formData();
    const guidelines = form.get('approvalGuidelines');
    const input: UpdateProjectInput = {
        name: String(form.get('name')),
        approvalMode: String(form.get('approvalMode')) as ApprovalMode,
        ...(null === guidelines ? {} : { approvalGuidelines: String(guidelines) }),
        repositoryIds: form.getAll('repositoryIds').map(String),
    };

    return writeResult(() => apiWrite('PATCH', `/api/v1/projects/${encodedRouteParam(params, 'projectKey')}`, input, request.signal));
}

/** The I1 `status` of the URL. An unknown value is no filter. */
export function statusFilter(search: URLSearchParams): IssueStatus | null {
    const status = search.get('status');

    return ISSUE_STATUSES.find((known) => known === status) ?? null;
}

/** The `label` of the URL, for I5 and the thread of an issue. An unknown value is no filter. */
export function labelFilter(search: URLSearchParams): CommentLabel | null {
    const label = search.get('label');

    return COMMENT_LABELS.find((known) => known === label) ?? null;
}

/** Every item of I1 or I5 with the URL filter. Neither has the project, so its name comes from P1. */
async function projectList<Item>({ request, params }: LoaderFunctionArgs, list: 'issues' | 'comments', filter: 'status' | 'label', value: string | null): Promise<{ project: Project; items: Item[] }> {
    const key = encodedRouteParam(params, 'projectKey');
    const [page, projects] = await Promise.all([
        apiGetAllPages<{ items: Item[]; nextCursor: NextCursor }>(`/api/v1/projects/${key}/${list}?limit=200${null === value ? '' : `&${filter}=${value}`}`, request.signal),
        allProjects(request.signal),
    ]);

    return { project: projectOf(projects, params), items: page.items };
}

export async function projectLoader(args: LoaderFunctionArgs): Promise<ProjectData> {
    const { project, items } = await projectList<IssueSummary>(args, 'issues', 'status', statusFilter(new URL(args.request.url).searchParams));

    return { project, issues: items };
}

/** Without a label it loads the body of every comment of the project. Page the list when a project outgrows that. */
export async function projectCommentsLoader(args: LoaderFunctionArgs): Promise<ProjectCommentsData> {
    const { project, items } = await projectList<IssueComment>(args, 'comments', 'label', labelFilter(new URL(args.request.url).searchParams));

    return { project, comments: items };
}

/** I9 has no project either, so its name comes from P1. */
export async function projectGraphLoader({ request, params }: LoaderFunctionArgs): Promise<ProjectGraphData> {
    const [graph, projects] = await Promise.all([apiGet<IssueGraph>(`/api/v1/projects/${encodedRouteParam(params, 'projectKey')}/graph`, request.signal), allProjects(request.signal)]);

    return { project: projectOf(projects, params), graph };
}

/** I2 with the replaced entries and the text of each section version, for the timeline. */
export function issueLoader({ request, params }: LoaderFunctionArgs): Promise<IssueDetail> {
    return apiGet<IssueDetail>(`/api/v1/issues/${encodedRouteParam(params, 'issueKey')}?history=true`, request.signal);
}

const ISSUE_WRITES: Record<IssueIntent['intent'], { method: 'POST' | 'PATCH'; path: string }> = {
    comment: { method: 'POST', path: '/comments' },
    approve_plan: { method: 'POST', path: '/plan-approval' },
    update: { method: 'PATCH', path: '' },
    set_status: { method: 'POST', path: '/status' },
    release: { method: 'POST', path: '/release' },
};

/**
 * I3, I4, I7, I8, and I11. The loader runs again after each write, also after a refusal, so the page shows the newest issue.
 * A refusal answers only its reasons, because each one shows at its form.
 */
export async function issueAction({ request, params }: ActionFunctionArgs): Promise<ActionResult> {
    const submitted = (await request.json()) as IssueIntent;
    const { method, path } = ISSUE_WRITES[submitted.intent];

    return writeResult(
        () => apiWrite(method, `/api/v1/issues/${encodedRouteParam(params, 'issueKey')}${path}`, submitted.input ?? undefined, request.signal),
        'draftKey' in submitted ? submitted.draftKey : undefined,
        refusalReasons,
    );
}
