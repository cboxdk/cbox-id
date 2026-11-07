import { useTranslator } from '@/i18n';
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
    kind: 'organizations' | 'users' | 'clients';
    href: string;
    selected: string[];
    initial: Option[];
    onChange: (ids: string[]) => void;
}) {
    const { t } = useTranslator();
    const selectedLabels = {
        organizations: t('console.catalog_selected_organizations'),
        users: t('console.catalog_selected_users'),
        clients: t('console.catalog_selected_clients'),
    };
    const searchLabels = {
        organizations: t('console.search_organizations_c1b2693a'),
        users: t('console.search_users_e4bb77af'),
        clients: t('console.catalog_search_clients'),
    };
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Option[]>([]);
    const [known, setKnown] = useState<Option[]>(initial);
    const [status, setStatus] = useState('');
    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setStatus(t('console.searching_c31723ab'));
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
                        ? t('console.catalog_search_limit')
                        : data.results.length === 0
                          ? t('console.catalog_search_empty')
                          : '',
                );
            } catch {
                if (!controller.signal.aborted) setStatus(t('console.catalog_search_failed'));
            }
        }, 250);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [href, kind, query, t]);
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
            <legend className="label">{selectedLabels[kind]}</legend>
            <Input
                aria-label={searchLabels[kind]}
                placeholder={searchLabels[kind]}
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
