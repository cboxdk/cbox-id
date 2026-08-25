import { providerMarks, providerMonograms } from './providerMarks';

export interface ProviderMarkProps {
    provider: string;
    size?: number;
}

/**
 * A sign-in provider's mark, at the one size that matters.
 *
 * Unknown providers get a monogram in a brand colour rather than a placeholder, which
 * reads as deliberate. A question mark would read as broken — and the catalogue has
 * eleven providers, so "unknown here" is an ordinary state rather than an error.
 */
export function ProviderMark({ provider, size = 18 }: ProviderMarkProps) {
    const mark = providerMarks[provider];

    if (mark === undefined) {
        const [letter, colour] = providerMonograms[provider] ?? [
            (provider === '' ? '?' : provider).charAt(0).toUpperCase(),
            'var(--accent)',
        ];

        return (
            <span
                aria-hidden="true"
                className="inline-flex items-center justify-center rounded-[4px] font-semibold shrink-0"
                style={{
                    width: `${size}px`,
                    height: `${size}px`,
                    background: colour,
                    color: '#fff',
                    fontSize: `${Math.max(9, Math.round(size * 0.58))}px`,
                    lineHeight: 1,
                }}
            >
                {letter}
            </span>
        );
    }

    const drawn = size - (mark.shrink ?? 0);

    return (
        <svg
            width={drawn}
            height={drawn}
            viewBox={mark.viewBox}
            fill={mark.currentColor === true ? 'currentColor' : undefined}
            aria-hidden="true"
            className="shrink-0"
        >
            {mark.shapes.map((shape) => (
                <path key={shape.d} fill={shape.fill} d={shape.d} />
            ))}
        </svg>
    );
}
