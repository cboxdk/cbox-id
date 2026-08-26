import { Button, Icon, Input } from '.';

export interface MetadataRow {
    key: string;
    value: string;
}

/**
 * Free-form key/value pairs on a record.
 *
 * ONE COMPONENT, because the create form and the edit form ask for the same thing and two
 * copies is how one of them comes to keep a row the other drops. The server drops blank
 * keys either way; this is only what the person types into.
 *
 * Rows are keyed by INDEX deliberately, which is the one place that is correct: a row has
 * no identity until it has a key, two blank rows are genuinely indistinguishable, and
 * keying by content would make React re-mount the input somebody is typing in.
 */
export function MetadataRows({
    rows,
    onChange,
    label = 'Metadata',
    hint,
    keyLabel = 'Key',
    valueLabel = 'Value',
    addLabel = 'Add row',
}: {
    rows: MetadataRow[];
    onChange: (rows: MetadataRow[]) => void;
    label?: string;
    hint?: string;
    /**
     * What the two columns ARE, when they are not "key" and "value".
     *
     * A screen reader announces these inputs and nothing else — there is no visible column
     * heading to fall back on — so a SAML attribute map read as "Key 1, Value 1" and left
     * the person to guess which side was which. The words are the label AND the
     * placeholder, so sighted and non-sighted readers are told the same thing.
     */
    keyLabel?: string;
    valueLabel?: string;
    addLabel?: string;
}) {
    const update = (index: number, patch: Partial<MetadataRow>): void => {
        onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    };

    return (
        <fieldset>
            <legend className="label">{label}</legend>

            {hint !== undefined && (
                <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                    {hint}
                </p>
            )}

            <div className="mt-2 space-y-2">
                {rows.map((row, index) => (
                    // eslint-disable-next-line react/no-array-index-key
                    <div key={index} className="flex items-center gap-2">
                        <Input
                            aria-label={`${keyLabel} ${index + 1}`}
                            className="mono"
                            placeholder={keyLabel.toLowerCase()}
                            value={row.key}
                            onChange={(event) => update(index, { key: event.target.value })}
                        />
                        <Input
                            aria-label={`${valueLabel} ${index + 1}`}
                            placeholder={valueLabel.toLowerCase()}
                            value={row.value}
                            onChange={(event) => update(index, { value: event.target.value })}
                        />
                        <Button
                            type="button"
                            size="sm"
                            className="shrink-0"
                            aria-label={`Remove row ${index + 1}`}
                            onClick={() => onChange(rows.filter((_, i) => i !== index))}
                        >
                            <Icon name="close" className="w-3.5 h-3.5" />
                        </Button>
                    </div>
                ))}
            </div>

            <Button
                type="button"
                size="sm"
                className="mt-2"
                onClick={() => onChange([...rows, { key: '', value: '' }])}
            >
                <Icon name="plus" className="w-3.5 h-3.5" />
                {addLabel}
            </Button>
        </fieldset>
    );
}
