import { wrapCreateBrowserRouter } from '@sentry/react';
import { createBrowserRouter, type LoaderFunctionArgs, redirect, redirectDocument } from 'react-router';
import { apiGet, signOut } from './api/client';
import type { Me } from './api/types/me';
import { commentAction } from './comments/commentAction';
import { LoadingPage } from './layout/LoadingPage';
import { RootLayout } from './layout/RootLayout';
import { RouteError } from './layout/RouteError';
import { removeSessionDrafts } from './layout/useSessionDraft';
import { issueAction, issueLoader, newIssueAction, newIssueLoader, projectAction, projectBoardLoader, projectCommentsLoader, projectGraphLoader, projectLoader, projectSettingsAction, projectSettingsLoader, projectsAction, projectsLoader } from './routes/issues/issueData';
import { ProjectRoute } from './routes/issues/ProjectRoute';
import { ProjectsRoute } from './routes/issues/ProjectsRoute';
import { RepositoriesRoute, repositoriesAction, repositoriesLoader } from './routes/repositories/RepositoriesRoute';
import { RepositoryRoute, repositoryAction, repositoryLoader } from './routes/repositories/RepositoryRoute';
import { commitDiffLoader, diffSidesLoader, shouldRevalidateChanges } from './routes/requests/changesData';
import { requestAction, requestListLoader, requestLoader } from './routes/requests/requestData';
import { carryOverSourcesLoader } from './routes/reviews/carryOverData';
import { CodeLayout, codeLayoutLoader, shouldRevalidateCodeLayout } from './routes/reviews/CodeLayout';
import { declarationsLoader, shouldRevalidateDeclarations } from './routes/reviews/declarationsData';
import { DirectoryRoute } from './routes/reviews/DirectoryRoute';
import { blameLoader, fileAction, fileLoader } from './routes/reviews/fileData';
import { ReviewLayout } from './routes/reviews/ReviewLayout';
import { reviewAction, reviewLoader } from './routes/reviews/reviewData';
import { searchLoader, shouldRevalidateSearch } from './routes/reviews/searchData';
import { shouldRevalidateSymbols, symbolsLoader } from './routes/reviews/symbolsData';
import { directoryLoader, shouldRevalidateTree, treeLoader } from './routes/reviews/treeData';
import { SettingsRoute } from './routes/settings/SettingsRoute';
import { settingsAction, settingsLoader } from './routes/settings/settingsData';
import { preferencesAction, shouldRevalidateRoot, tourTokensLoader } from './tour/useTours';

export const router = wrapCreateBrowserRouter(createBrowserRouter)([
    {
        id: 'root',
        path: '/',
        loader: ({ request }: LoaderFunctionArgs): Promise<Me> => apiGet<Me>('/api/v1/me', request.signal),
        shouldRevalidate: shouldRevalidateRoot,
        element: <RootLayout />,
        errorElement: <RouteError />,
        hydrateFallbackElement: <LoadingPage />,
        children: [
            {
                errorElement: <RouteError embedded />,
                children: [
                    { index: true, loader: () => redirect('/repositories') },
                    { path: 'repositories', loader: repositoriesLoader, action: repositoriesAction, element: <RepositoriesRoute /> },
                    { path: 'repositories/:repositoryId', loader: repositoryLoader, action: repositoryAction, element: <RepositoryRoute /> },
                    {
                        id: 'review',
                        path: 'reviews/:reviewId',
                        loader: reviewLoader,
                        action: reviewAction,
                        element: <ReviewLayout />,
                        children: [
                            {
                                loader: codeLayoutLoader,
                                shouldRevalidate: shouldRevalidateCodeLayout,
                                element: <CodeLayout />,
                                errorElement: <RouteError embedded />,
                                children: [
                                    { index: true, loader: directoryLoader, element: <DirectoryRoute />, errorElement: <RouteError embedded /> },
                                    { path: 'tree/*', loader: directoryLoader, element: <DirectoryRoute />, errorElement: <RouteError embedded /> },
                                    {
                                        path: 'files/*',
                                        loader: fileLoader,
                                        action: fileAction,
                                        // The loader stays in the shell bundle and runs while the component chunk loads.
                                        lazy: { Component: () => import('./routes/reviews/FileRoute').then(({ FileRoute }) => FileRoute) },
                                        errorElement: <RouteError embedded />,
                                    },
                                    {
                                        path: 'blame/*',
                                        loader: blameLoader,
                                        action: fileAction,
                                        lazy: { Component: () => import('./routes/reviews/FileRoute').then(({ FileRoute }) => FileRoute) },
                                        errorElement: <RouteError embedded />,
                                    },
                                ],
                            },
                            { path: 'tree-entries', loader: treeLoader, shouldRevalidate: shouldRevalidateTree },
                            { path: 'symbols', loader: symbolsLoader, shouldRevalidate: shouldRevalidateSymbols },
                            { path: 'declarations', loader: declarationsLoader, shouldRevalidate: shouldRevalidateDeclarations },
                            { path: 'search', loader: searchLoader, shouldRevalidate: shouldRevalidateSearch },
                            {
                                path: 'requests',
                                loader: requestListLoader,
                                action: commentAction,
                                lazy: { Component: () => import('./routes/requests/RequestList').then(({ RequestList }) => RequestList) },
                                errorElement: <RouteError embedded />,
                            },
                        ],
                    },
                    {
                        id: 'request',
                        path: 'requests/:requestId',
                        loader: requestLoader,
                        action: requestAction,
                        lazy: { Component: () => import('./routes/requests/RequestDetail').then(({ RequestDetail }) => RequestDetail) },
                        children: [{ path: 'commits/:commitSha', loader: commitDiffLoader, shouldRevalidate: shouldRevalidateChanges }],
                    },
                    { path: 'repositories/:repositoryId/diff-sides', loader: diffSidesLoader, shouldRevalidate: shouldRevalidateChanges },
                    { path: 'repositories/:repositoryId/carry-over-sources', loader: carryOverSourcesLoader },
                    { path: 'projects', loader: projectsLoader, action: projectsAction, element: <ProjectsRoute /> },
                    { id: 'project', path: 'projects/:projectKey', loader: projectLoader, action: projectAction, element: <ProjectRoute /> },
                    {
                        id: 'project-comments',
                        path: 'projects/:projectKey/comments',
                        loader: projectCommentsLoader,
                        lazy: { Component: () => import('./routes/issues/ProjectCommentsRoute').then(({ ProjectCommentsRoute }) => ProjectCommentsRoute) },
                    },
                    {
                        id: 'project-graph',
                        path: 'projects/:projectKey/graph',
                        loader: projectGraphLoader,
                        // The chunk holds the layout library, so the shell bundle does not.
                        lazy: { Component: () => import('./routes/issues/ProjectGraphRoute').then(({ ProjectGraphRoute }) => ProjectGraphRoute) },
                    },
                    {
                        id: 'project-board',
                        path: 'projects/:projectKey/board',
                        loader: projectBoardLoader,
                        lazy: { Component: () => import('./routes/issues/ProjectBoardRoute').then(({ ProjectBoardRoute }) => ProjectBoardRoute) },
                    },
                    {
                        path: 'projects/:projectKey/settings',
                        loader: projectSettingsLoader,
                        action: projectSettingsAction,
                        lazy: { Component: () => import('./routes/issues/ProjectSettingsRoute').then(({ ProjectSettingsRoute }) => ProjectSettingsRoute) },
                    },
                    {
                        path: 'projects/:projectKey/issues/new',
                        loader: newIssueLoader,
                        action: newIssueAction,
                        lazy: { Component: () => import('./routes/issues/NewIssueRoute').then(({ NewIssueRoute }) => NewIssueRoute) },
                    },
                    {
                        id: 'issue',
                        path: 'issues/:issueKey',
                        loader: issueLoader,
                        action: issueAction,
                        lazy: { Component: () => import('./routes/issues/IssueRoute').then(({ IssueRoute }) => IssueRoute) },
                    },
                    { path: 'settings', loader: settingsLoader, action: settingsAction, element: <SettingsRoute /> },
                ],
            },
            { path: 'preferences', action: preferencesAction },
            { path: 'tour-tokens', loader: tourTokensLoader },
            {
                path: 'sign-out',
                action: async (): Promise<Response> => {
                    await signOut();
                    // The next person in this tab does not see the text (D109).
                    removeSessionDrafts();

                    return redirectDocument('/login');
                },
            },
        ],
    },
]);
