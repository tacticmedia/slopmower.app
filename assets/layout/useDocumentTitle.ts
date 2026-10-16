import { useEffect } from 'react';

const APP_NAME = 'Slopmower';

export function useDocumentTitle(title: string): void {
    useEffect(() => {
        document.title = `${title} · ${APP_NAME}`;

        return () => {
            document.title = APP_NAME;
        };
    }, [title]);
}
