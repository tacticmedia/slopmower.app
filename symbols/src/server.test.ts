import { type ChildProcessWithoutNullStreams, spawn } from 'node:child_process';
import { once } from 'node:events';
import { createServer } from 'node:net';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, expect, it } from 'vitest';

type Service = { process: ChildProcessWithoutNullStreams; exited: Promise<[code: number | null, signal: string | null]>; stderr: () => string };

const SERVER = fileURLToPath(new URL('./server.ts', import.meta.url));

const services: Service[] = [];

/** Empty variables mean the defaults. */
function start(variables: Record<string, string>): Service {
    const child = spawn(process.execPath, [SERVER], { env: { ...process.env, SYMBOLS_HOST: '', SYMBOLS_PORT: '', ...variables } });
    let stderr = '';
    child.stderr.on('data', (chunk: Buffer) => (stderr += chunk.toString()));
    const service: Service = { process: child, exited: once(child, 'exit') as Service['exited'], stderr: () => stderr };
    services.push(service);

    return service;
}

/** The host and the port of the start line. */
async function listening(service: Service): Promise<{ host: string; port: number }> {
    let stdout = '';

    for await (const chunk of service.process.stdout) {
        stdout += (chunk as Buffer).toString();
        const [, host, port] = /^Listening on (.+):(\d+): /m.exec(stdout) ?? [];

        if (undefined !== host && undefined !== port) {
            return { host, port: Number(port) };
        }
    }

    throw new Error(`The service stopped before it listened: ${stdout}${service.stderr()}`);
}

async function freePort(host: string): Promise<number> {
    const probe = createServer().listen(0, host);
    await once(probe, 'listening');
    const { port } = probe.address() as { port: number };
    probe.close();
    await once(probe, 'close');

    return port;
}

/** Another process can take the free port before the service binds it, so a taken port gets a new one. */
async function listeningOnFreePort(host: string): Promise<{ free: number; host: string; port: number }> {
    for (let attempt = 1; ; attempt++) {
        const free = await freePort(host);
        const service = start({ SYMBOLS_HOST: host, SYMBOLS_PORT: String(free) });
        try {
            return { free, ...(await listening(service)) };
        } catch (error) {
            if (3 === attempt || !service.stderr().includes('EADDRINUSE')) {
                throw error;
            }
        }
    }
}

async function health(port: number): Promise<number> {
    return (await fetch(`http://127.0.0.1:${port}/healthz`)).status;
}

afterEach(async () => {
    await Promise.all(
        services.splice(0).map(async (service) => {
            service.process.kill();
            await service.exited;
        }),
    );
});

describe('ServerTest', { timeout: 20_000 }, () => {
    it('Without SYMBOLS_HOST, the service listens on 127.0.0.1', async () => {
        const { host, port } = await listening(start({ SYMBOLS_PORT: '0' }));

        expect(host).toBe('127.0.0.1');
        expect(await health(port)).toBe(200);
    });

    it('SYMBOLS_HOST and SYMBOLS_PORT set the listen address', async () => {
        const { free, host, port } = await listeningOnFreePort('0.0.0.0');

        expect([host, port]).toEqual(['0.0.0.0', free]);
        expect(await health(port)).toBe(200);
    });

    it('An invalid SYMBOLS_PORT stops the service with exit code 1', async () => {
        const service = start({ SYMBOLS_PORT: 'http' });
        const [code] = await service.exited;

        expect(code).toBe(1);
        expect(service.stderr()).toContain('ERR_SOCKET_BAD_PORT');
    });
});
