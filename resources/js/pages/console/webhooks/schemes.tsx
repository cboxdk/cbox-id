import type { RadioOption } from '@/ui';

/**
 * How an endpoint's deliveries are signed — the two schemes the framework offers, named
 * the way the API names them.
 *
 * Shared by the create form, the detail page's "Change scheme" and the list's badge, so
 * the three never describe the same scheme two ways.
 */
export type SignatureScheme = 'cbox' | 'standard_webhooks';

export const signatureSchemeLabels: Record<SignatureScheme, string> = {
    cbox: 'Cbox',
    standard_webhooks: 'Standard Webhooks',
};

export const signatureSchemeOptions: RadioOption<SignatureScheme>[] = [
    {
        value: 'cbox',
        label: signatureSchemeLabels.cbox,
        hint: 'X-Cbox-Signature with a hex HMAC — the default, verified with the Cbox SDKs.',
    },
    {
        value: 'standard_webhooks',
        label: signatureSchemeLabels.standard_webhooks,
        hint: 'webhook-id, webhook-timestamp and webhook-signature, with a whsec_ secret — verified by any Standard Webhooks library.',
    },
];
