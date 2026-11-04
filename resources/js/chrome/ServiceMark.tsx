import type { CSSProperties, ReactNode } from 'react';

const marks: Record<string, [string, string, ReactNode]> = {
    id: [
        '#7743bc',
        '#c29bef',
        <>
            <rect x="2" y="4" width="20" height="16" rx="3" />
            <circle cx="8" cy="10" r="2" />
            <path d="M5 16q3-5 6 0m3-6h5m-5 4h4" />
        </>,
    ],
    billing: [
        '#197351',
        '#82ddb1',
        <>
            <rect x="2" y="4" width="20" height="16" rx="3" />
            <path d="M3 10h18" />
        </>,
    ],
    tax: [
        '#985906',
        '#efb850',
        <>
            <path d="m5 20 14-16" />
            <circle cx="6.5" cy="7" r="3" />
            <circle cx="17.5" cy="17" r="3" />
        </>,
    ],
    domains: [
        '#117578',
        '#76d2cb',
        <>
            <circle cx="12" cy="12" r="9.5" />
            <ellipse cx="12" cy="12" rx="4.3" ry="9.5" />
            <path d="M3 9h18M3 15h18" />
        </>,
    ],
    sites: [
        '#6945b8',
        '#bba3f0',
        <>
            <rect x="2" y="4" width="20" height="16" rx="3" />
            <path d="M2 10h20m-15-3h.1m3 0h.1" />
        </>,
    ],
    stache: [
        '#b65046',
        '#ff9588',
        <>
            <rect x="2" y="3" width="20" height="8" rx="2" />
            <rect x="2" y="13" width="20" height="8" rx="2" />
            <path d="M6 7h.1M6 17h.1M15 6v2m3-2v2m-3 8v2m3-2v2" />
        </>,
    ],
    telemetry: ['#235eb8', '#7fb6ff', <path key="pulse" d="M1.5 13h5L10 4l4.5 16 3.5-9h4.5" />],
    engage: [
        '#a13d74',
        '#f093c5',
        <path
            key="bubble"
            d="M5 3.5h14a3 3 0 0 1 3 3v9a3 3 0 0 1-3 3h-7l-6 4v-4H5a3 3 0 0 1-3-3v-9a3 3 0 0 1 3-3Z"
        />,
    ],
    work: [
        '#94620a',
        '#eac16b',
        <>
            <rect x="2" y="7" width="20" height="14" rx="2.5" />
            <path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M2 13h20m-10-1v3" />
        </>,
    ],
    agents: [
        '#3e657d',
        '#98cce4',
        <>
            <rect x="3" y="6" width="18" height="14" rx="4" />
            <path d="M12 3v3M8 12h.1m8 0h.1m-7 4h6" />
        </>,
    ],
};

export function ServiceMark({ service }: { service: string }) {
    const mark = Object.hasOwn(marks, service) ? marks[service] : null;
    if (!mark) return null;
    const [light, dark, drawing] = mark;
    return (
        <svg
            aria-hidden="true"
            className="cbx-service-mark"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.9"
            strokeLinecap="round"
            strokeLinejoin="round"
            style={{ '--mark-light': light, '--mark-dark': dark } as CSSProperties}
        >
            {drawing}
        </svg>
    );
}

export function hasServiceMark(key: string) {
    return Object.hasOwn(marks, key);
}
