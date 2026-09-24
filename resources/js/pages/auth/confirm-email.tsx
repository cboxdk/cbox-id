import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { LinkConfirmation, type LinkConfirmationContent } from '@/ui';

type Props = PageProps<{ confirmation: LinkConfirmationContent }>;

/** What the address-confirmation link opens — a button, never the spending itself. See `LinkConfirmation`. */
export default function ConfirmEmail({ confirmation }: Props) {
    return <LinkConfirmation confirmation={confirmation} />;
}

ConfirmEmail.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
