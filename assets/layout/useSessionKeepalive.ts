import { useEffect, useState } from 'react';
import { ApiError, apiGet } from '../api/client';

/** Well inside `session.gc_maxlifetime`, 24 minutes, also with the timer throttling of a hidden tab. */
const MIN_DELAY_MS = 4 * 60_000;
const MAX_DELAY_MS = 8 * 60_000;

/**
 * Sends A1 after a random delay and when the tab becomes visible, so the session of an open page does not expire.
 * True once A1 answers 401; then it stops. Another failure waits for the next request (D109).
 */
export function useSessionKeepalive(): boolean {
    const [expired, setExpired] = useState(false);

    useEffect(() => {
        let active = true;
        let pinging = false;
        let timer = 0;
        const schedule = (): void => {
            timer = window.setTimeout(() => void ping(), MIN_DELAY_MS + Math.random() * (MAX_DELAY_MS - MIN_DELAY_MS));
        };
        const ping = async (): Promise<void> => {
            if (!active || pinging) {
                return;
            }
            pinging = true;
            window.clearTimeout(timer);
            try {
                await apiGet('/api/v1/me');
            } catch (error) {
                if (active && error instanceof ApiError && 401 === error.status) {
                    active = false;
                    setExpired(true);
                }
            } finally {
                pinging = false;
            }
            if (active) {
                schedule();
            }
        };
        const pingWhenVisible = (): void => {
            if ('visible' === document.visibilityState) {
                void ping();
            }
        };

        schedule();
        document.addEventListener('visibilitychange', pingWhenVisible);

        return () => {
            active = false;
            window.clearTimeout(timer);
            document.removeEventListener('visibilitychange', pingWhenVisible);
        };
    }, []);

    return expired;
}
