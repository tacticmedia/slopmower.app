import { createBrowserRouter, type LoaderFunctionArgs, redirectDocument } from 'react-router';
import { apiGet, signOut } from './api/client';
import type { Me } from './api/types/me';
import { LoadingPage } from './layout/LoadingPage';
import { RootLayout } from './layout/RootLayout';
import { RouteError } from './layout/RouteError';
import { HomeRoute } from './routes/HomeRoute';

export const router = createBrowserRouter([
    {
        id: 'root',
        path: '/',
        loader: ({ request }: LoaderFunctionArgs): Promise<Me> => apiGet<Me>('/api/v1/me', request.signal),
        element: <RootLayout />,
        errorElement: <RouteError />,
        hydrateFallbackElement: <LoadingPage />,
        children: [
            { index: true, element: <HomeRoute /> },
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
