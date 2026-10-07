import Symfony from '@symfony/reprise/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

export default defineConfig(({ mode }) => ({
    input: {
        app: './assets/app.tsx',
    },
    // The .env files in the project root belong to Symfony.
    envDir: false,
    plugins: [
        react(),
        // public/entrypoints.json is the file that the dev worker watches in docker/frankenphp/Caddyfile.
        Symfony('e2e' === mode ? { outputPath: 'public/build-e2e', publicPath: '/build-e2e/' } : { metadataPath: 'public' }),
    ],
    // ES workers split chunks: the @pierre/diffs worker then loads the Shiki WASM engine only on demand.
    worker: {
        format: 'es',
    },
    test: {
        include: ['assets/**/*.test.ts', 'symbols/**/*.test.ts'],
    },
}));
