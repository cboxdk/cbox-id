import { useEffect, useState } from 'react';
import { Checkbox, Input } from '@/ui';
export interface Option {
    value: string;
    label: string;
}
export function Targets({
    kind,
    href,
    selected,
    initial,
    onChange,
}: {
    kind: string;
    href: string;
    selected: string[];
    initial: Option[];
    onChange: (ids: string[]) => void;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Option[]>([]);
    const [known, setKnown] = useState<Option[]>(initial);
    const [status, setStatus] = useState('');
    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setStatus('Searching…');
            try {
                const url = new URL(href, window.location.origin);
                url.searchParams.set('kind', kind);
                url.searchParams.set('q', query);
                const response = await fetch(url, {
                    signal: controller.signal,
                    headers: { Accept: 'application/json' },
                });
                if (!response.ok) throw new Error('Search failed');
                const data: { results: Option[] } = await response.json();
                setResults(data.results);
                setKnown((old) => [
                    ...new Map(
                        [...old, ...data.results].map((option) => [option.value, option]),
                    ).values(),
                ]);
                setStatus(
                    data.results.length === 20
                        ? 'First 20 matches. Type to narrow the search.'
                        : data.results.length === 0
                          ? 'No matches.'
                          : '',
                );
            } catch {
                if (!controller.signal.aborted) setStatus('Search could not load. Try again.');
            }
        }, 250);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [href, kind, query]);
    const options = [
        ...new Map(
            [
                ...selected.map(
                    (id) => known.find((option) => option.value === id) ?? { value: id, label: id },
                ),
                ...results,
            ].map((option) => [option.value, option]),
        ).values(),
    ];
    return (
        <fieldset className="space-y-3">
            <legend className="label">Selected {kind}</legend>
            <Input
                aria-label={`Search ${kind}`}
                placeholder={`Search ${kind}…`}
                value={query}
                maxLength={120}
                onChange={(event) => setQuery(event.target.value)}
            />
            <div className="max-h-56 overflow-y-auto space-y-2">
                {options.map((option) => (
                    <Checkbox
                        key={option.value}
                        label={option.label}
                        checked={selected.includes(option.value)}
                        onCheckedChange={(checked) =>
                            onChange(
                                checked
                                    ? [...selected, option.value]
                                    : selected.filter((id) => id !== option.value),
                            )
                        }
                    />
                ))}
            </div>
            <output className="block text-xs text-[var(--muted-foreground)]">{status}</output>
        </fieldset>
    );
}
