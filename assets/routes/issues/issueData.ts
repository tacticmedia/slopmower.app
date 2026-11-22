import type { ActionFunctionArgs, LoaderFunctionArgs } from 'react-router';
import { type ActionResult, writeResult } from '../../api/actionResult';
import { apiGet, apiGetAllPages, apiWrite } from '../../api/client';
import { ISSUE_STATUSES, type IssueStatus } from '../../api/types/common';
import type { ApprovePlanInput, IssueCommentInput } from '../../api/types/inputs';
import type { IssueDetail, IssueSummary } from '../../api/types/issue';
import type { IssuePage, ProjectPage } from '../../api/types/lists';
import type { Project } from '../../api/types/project';
import { encodedRouteParam } from '../encodedRouteParam';

/** The JSON body of the writes of the issue page. The action removes the session draft `draftKey` after the comment (D109). */
export type IssueIntent = { intent: 'comment'; input: IssueCommentInput; draftKey: string } | { intent: 'approve_plan'; input: ApprovePlanInput };

export type ProjectData = {
    project: Project;
    issues: IssueSummary[];
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

/** I1 has no project, so its name comes from P1. */
export async function projectLoader({ request, params }: LoaderFunctionArgs): Promise<ProjectData> {
    const key = encodedRouteParam(params, 'projectKey');
    const status = statusFilter(new URL(request.url).searchParams);
    const [issues, projects] = await Promise.all([
        apiGetAllPages<IssuePage>(`/api/v1/projects/${key}/issues?limit=200${null === status ? '' : `&status=${status}`}`, request.signal),
        allProjects(request.signal),
    ]);
    const project = projects.find((known) => known.key === params.projectKey);
    if (undefined === project) {
        throw new Error(`P1 does not list the project ${key}.`);
    }

    return { project, issues: issues.items };
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
