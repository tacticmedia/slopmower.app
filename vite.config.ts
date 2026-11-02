import { sentryVitePlugin } from '@sentry/vite-plugin';
import Symfony from '@symfony/reprise/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

// D100.
const uploadSourceMaps = Boolean(process.env.SENTRY_PROJECT);
// Without the organization or the token, the plugin only warns and skips the upload.
if (uploadSourceMaps && !(process.env.SENTRY_ORG && process.env.SENTRY_AUTH_TOKEN)) {
    throw new Error('SENTRY_PROJECT is set, so the build uploads source maps and needs SENTRY_ORG and SENTRY_AUTH_TOKEN.');
}

export default defineConfig(({ mode }) => ({
    input: {
        app: './assets/app.tsx',
    },
    // The .env files in the project root belong to Symfony.
    envDir: false,
    build: {
        sourcemap: uploadSourceMaps ? 'hidden' : false,
    },
    plugins: [
        react(),
        // public/entrypoints.json is the file that the dev worker watches in docker/frankenphp/Caddyfile.
        Symfony('e2e' === mode ? { outputPath: 'public/build-e2e', publicPath: '/build-e2e/' } : { metadataPath: 'public' }),
        sentryVitePlugin({
            disable: !uploadSourceMaps,
            telemetry: false,
            // Empty: no release created, injected, or attached to the upload. The SDK takes APP_RELEASE at run time.
            release: { name: '' },
            errorHandler: (error) => {
                throw error;
            },
            sourcemaps: { filesToDeleteAfterUpload: ['public/build*/**/*.map'] },
        }),
    ],
    // ES workers split chunks: the @pierre/diffs worker then loads the Shiki WASM engine only on demand.
    worker: {
        format: 'es',
    },
    test: {
        include: ['assets/**/*.test.ts', 'symbols/**/*.test.ts'],
    },
}));
