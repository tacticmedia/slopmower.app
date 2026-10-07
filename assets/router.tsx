import { createBrowserRouter, type LoaderFunctionArgs, redirectDocument } from 'react-router';
import { apiGet, signOut } from './api/client';
import type { Me } from './api/types/me';
import { commentAction } from './comments/commentAction';
import { LoadingPage } from './layout/LoadingPage';
import { RootLayout } from './layout/RootLayout';
import { RouteError } from './layout/RouteError';
import { HomeRoute } from './routes/HomeRoute';
import { RepositoriesRoute, repositoriesAction, repositoriesLoader } from './routes/repositories/RepositoriesRoute';
import { RepositoryRoute, repositoryAction, repositoryLoader } from './routes/repositories/RepositoryRoute';
import { commitDiffLoader, diffSidesLoader, shouldRevalidateChanges } from './routes/requests/changesData';
import { requestAction, requestListLoader, requestLoader } from './routes/requests/requestData';
import { CodeLayout, codeLayoutLoader, CodeRouteError, NoFileSelected } from './routes/reviews/CodeLayout';
import { declarationsLoader, shouldRevalidateDeclarations } from './routes/reviews/declarationsData';
import { fileAction, fileLoader } from './routes/reviews/fileData';
import { ReviewLayout } from './routes/reviews/ReviewLayout';
import { reviewAction, reviewLoader } from './routes/reviews/reviewData';
import { searchLoader, shouldRevalidateSearch } from './routes/reviews/searchData';
import { shouldRevalidateSymbols, symbolsLoader } from './routes/reviews/symbolsData';
import { treeLoader } from './routes/reviews/treeData';
import { SettingsRoute } from './routes/settings/SettingsRoute';
import { settingsAction, settingsLoader } from './routes/settings/settingsData';

export const router = createBrowserRouter([
    {
        id: 'root',
        path: '/',
        loader: ({ request }: LoaderFunctionArgs): Promise<Me> => apiGet<Me>('/api/v1/me', request.signal),
        element: <RootLayout />,
        errorElement: <RouteError />,
        hydrateFallbackElement: <LoadingPage />,
        children: [
            {
                errorElement: <RouteError embedded />,
                children: [
                    { index: true, element: <HomeRoute /> },
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
                                element: <CodeLayout />,
                                errorElement: <CodeRouteError />,
                                children: [
                                    { index: true, element: <NoFileSelected /> },
                                    {
                                        path: 'files/*',
                                        loader: fileLoader,
                                        action: fileAction,
                                        // The loader stays in the shell bundle and runs while the component chunk loads.
                                        lazy: { Component: () => import('./routes/reviews/FileRoute').then(({ FileRoute }) => FileRoute) },
                                        errorElement: <CodeRouteError />,
                                    },
                                ],
                            },
                            { path: 'tree', loader: treeLoader },
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
                    { path: 'settings', loader: settingsLoader, action: settingsAction, element: <SettingsRoute /> },
                ],
            },
            {
                path: 'sign-out',
                action: async (): Promise<Response> => {
                    await signOut();

                    return redirectDocument('/login');
                },
            },
        ],
    },
]);
