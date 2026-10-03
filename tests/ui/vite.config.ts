import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

/** The SPA of `templates/shell.html.twig` without Symfony, for the mocked UI suite. */
export default defineConfig({
    root: fileURLToPath(new URL('./app', import.meta.url)),
    envDir: false,
    plugins: [react()],
    worker: {
        format: 'es',
    },
    build: {
        outDir: fileURLToPath(new URL('../../var/ui/build', import.meta.url)),
        emptyOutDir: true,
        chunkSizeWarningLimit: 1000,
    },
    logLevel: 'warn',
});
