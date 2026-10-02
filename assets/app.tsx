import '@primer/primitives/dist/css/functional/themes/light.css';
import '@primer/primitives/dist/css/functional/themes/dark.css';
// Motion, size, space, and typography tokens.
import '@primer/primitives/dist/css/primitives.css';
import { BaseStyles } from '@primer/react';
import { ThemeProvider } from '@primer/react/next';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { RouterProvider } from 'react-router/dom';
import { router } from './router';

const container = document.getElementById('root');
if (null === container) {
    throw new Error('The shell template has no #root element.');
}

createRoot(container).render(
    <StrictMode>
        <ThemeProvider colorMode="auto">
            <BaseStyles>
                <RouterProvider router={router} />
            </BaseStyles>
        </ThemeProvider>
    </StrictMode>,
);
