import { CreateField, CreateForm, CreateSubmit, type ShellCreateForm } from '@cboxdk/cbox-ui';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { ShellCreateFieldSpec } from '@/types';

/** The values a form starts with: each field's default, else empty. */
export function initialValues(fields: ShellCreateFieldSpec[]): Record<string, string | boolean> {
    return Object.fromEntries(
        fields.map((field) => [
            field.name,
            field.default ??
                (field.type === 'checkbox'
                    ? false
                    : field.type === 'select'
                      ? (field.options?.[0]?.value ?? '')
                      : ''),
        ]),
    );
}

/** What the form posts: a list field as a one-item list (`roles[]`), blanks as blanks. */
export function payload(
    fields: ShellCreateFieldSpec[],
    values: Record<string, string | boolean>,
): Record<string, string | boolean | string[]> {
    return Object.fromEntries(
        fields.map((field) => {
            const value = values[field.name] ?? '';

            return [field.name, field.array === true ? [String(value)] : value];
        }),
    );
}

/**
 * ONE CREATE FORM IN THE SHELL'S "+" SHEET: the page's own create form's fields
 * (`App\Platform\Shell\IdCreatables`), posted to the route that page posts to, so a refusal, a
 * validation error and the page it lands on are the page's own. The sheet closes once the
 * server has answered with somewhere to go.
 */
export function ShellCreateFormFields({
    form,
    url,
    fields,
}: {
    form: ShellCreateForm;
    url: string;
    fields: ShellCreateFieldSpec[];
}) {
    const [values, setValues] = useState(() => initialValues(fields));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    return (
        <CreateForm
            onSubmit={(event) => {
                event.preventDefault();
                setBusy(true);
                router.post(url, payload(fields, values), {
                    onError: (answer) => setErrors(answer),
                    onSuccess: () => form.done(),
                    onFinish: () => setBusy(false),
                });
            }}
        >
            {fields.map((field) => {
                const id = `shell-create-${form.creatable.key}-${field.name}`;
                const error = errors[field.name] ?? errors[`${field.name}.0`];
                const value = values[field.name];

                if (field.type === 'checkbox') {
                    return (
                        <label
                            key={field.name}
                            htmlFor={id}
                            className="flex items-center gap-2 text-sm"
                        >
                            <input
                                id={id}
                                type="checkbox"
                                checked={value === true}
                                onChange={(event) =>
                                    setValues((current) => ({
                                        ...current,
                                        [field.name]: event.target.checked,
                                    }))
                                }
                            />
                            {field.label}
                        </label>
                    );
                }

                return (
                    <CreateField key={field.name} label={field.label} htmlFor={id}>
                        {field.type === 'select' ? (
                            <select
                                id={id}
                                className="cbx-cinput"
                                value={String(value)}
                                required={field.required === true}
                                onChange={(event) =>
                                    setValues((current) => ({
                                        ...current,
                                        [field.name]: event.target.value,
                                    }))
                                }
                            >
                                {(field.options ?? []).map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <input
                                id={id}
                                className="cbx-cinput"
                                type={field.type}
                                value={String(value)}
                                required={field.required === true}
                                aria-invalid={error !== undefined}
                                aria-describedby={error !== undefined ? `${id}-error` : undefined}
                                onChange={(event) =>
                                    setValues((current) => ({
                                        ...current,
                                        [field.name]: event.target.value,
                                    }))
                                }
                            />
                        )}
                        {field.hint !== undefined && error === undefined && (
                            <span className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                                {field.hint}
                            </span>
                        )}
                        {error !== undefined && (
                            <span
                                id={`${id}-error`}
                                role="alert"
                                className="text-xs"
                                style={{ color: 'var(--destructive-text)' }}
                            >
                                {error}
                            </span>
                        )}
                    </CreateField>
                );
            })}
            <CreateSubmit form={form} busy={busy} />
        </CreateForm>
    );
}
