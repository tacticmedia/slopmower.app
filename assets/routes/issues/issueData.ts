import type { ActionFunctionArgs, LoaderFunctionArgs } from 'react-router';
import { type ActionResult, writeResult } from '../../api/actionResult';
import { apiGet, apiGetAllPages, apiWrite } from '../../api/client';
import { COMMENT_LABELS, type CommentLabel, ISSUE_STATUSES, type IssueStatus } from '../../api/types/common';
import type { ApprovePlanInput, IssueCommentInput } from '../../api/types/inputs';
import type { IssueComment, IssueDetail, IssueSummary } from '../../api/types/issue';
import type { NextCursor, ProjectPage } from '../../api/types/lists';
import type { Project } from '../../api/types/project';
import { encodedRouteParam } from '../encodedRouteParam';

/** The JSON body of the writes of the issue page. The action removes the session draft `draftKey` after the comment (D109). */
export type IssueIntent = { intent: 'comment'; input: IssueCommentInput; draftKey: string } | { intent: 'approve_plan'; input: ApprovePlanInput };

export type ProjectData = {
    project: Project;
    issues: IssueSummary[];
};

export type ProjectCommentsData = {
    project: Project;
    comments: IssueComment[];
};

async function allProjects(signal: AbortSignal): Promise<Project[]> {
    return (await apiGetAllPages<ProjectPage>('/api/v1/projects?limit=500', signal)).items;
}

export function projectsLoader({ request }: LoaderFunctionArgs): Promise<Project[]> {
    return allProjects(request.signal);
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
    const project = projects.find((known) => known.key === params.projectKey);
    if (undefined === project) {
        throw new Error(`P1 does not list the project ${key}.`);
    }

    return { project, items: page.items };
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

export function issueLoader({ request, params }: LoaderFunctionArgs): Promise<IssueDetail> {
    return apiGet<IssueDetail>(`/api/v1/issues/${encodedRouteParam(params, 'issueKey')}`, request.signal);
}

/** I3 and I4. The loader runs again after each write, also after a refusal, so the page shows the newest plan. */
export async function issueAction({ request, params }: ActionFunctionArgs): Promise<ActionResult> {
    const submitted = (await request.json()) as IssueIntent;
    const issue = `/api/v1/issues/${encodedRouteParam(params, 'issueKey')}`;

    return 'comment' === submitted.intent
        ? writeResult(() => apiWrite('POST', `${issue}/comments`, submitted.input, request.signal), submitted.draftKey)
        : writeResult(() => apiWrite('POST', `${issue}/plan-approval`, submitted.input, request.signal));
}
