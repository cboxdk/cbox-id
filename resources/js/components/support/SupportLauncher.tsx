import { useEffect, useRef } from 'react';

/** Only the mount point is product-specific; chat and identity refresh live in the SDK. */
export function SupportLauncher({ sessionUrl }: { sessionUrl: string | null }) {
    const root = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (!sessionUrl || !root.current) return;
        const element = document.createElement('cbox-support');
        element.setAttribute('session-url', sessionUrl);
        root.current.appendChild(element);
        return () => element.remove();
    }, [sessionUrl]);
    return sessionUrl ? <div ref={root} /> : null;
}
