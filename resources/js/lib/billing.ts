/** Provider amounts are integer minor units, including zero- and three-decimal currencies. */
export function billingMoney(amount: number, currency: string, locale: string): string {
    const format = new Intl.NumberFormat(locale, { style: 'currency', currency });
    return format.format(amount / 10 ** (format.resolvedOptions().maximumFractionDigits ?? 2));
}

/** Fetch only our authenticated app; never send a card number or a Stripe secret here. */
export async function billingRequest<T>(
    path: string,
    organization: string,
    data: Record<string, string> = {},
): Promise<T> {
    const response = await fetch(path, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN':
                document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ ...data, organization }),
    });
    if (!response.ok) throw new Error(String(response.status));
    return response.json() as Promise<T>;
}
