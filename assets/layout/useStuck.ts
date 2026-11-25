import { type RefObject, useEffect, useState } from 'react';

/** Whether a sticky element sits at the window top: the sentinel right above it has left the window upwards. */
export function useStuck(sentinel: RefObject<HTMLElement | null>): boolean {
    const [stuck, setStuck] = useState(false);

    useEffect(() => {
        const element = sentinel.current;
        if (null === element) {
            return;
        }
        // A slow client reports several changes in one callback, oldest first.
        const observer = new IntersectionObserver((entries) => {
            const entry = entries.at(-1);
            setStuck(undefined !== entry && !entry.isIntersecting && entry.boundingClientRect.top < 0);
        });
        observer.observe(element);

        return () => observer.disconnect();
    }, [sentinel]);

    return stuck;
}
