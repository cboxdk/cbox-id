import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { LinkConfirmation, type LinkConfirmationContent } from '@/ui';

type Props = PageProps<{ confirmation: LinkConfirmationContent }>;

/** What the sign-in link a person asked for opens — a button, never the spending itself. See `LinkConfirmation`. */
export default function ConfirmSignIn({ confirmation }: Props) {
    return <LinkConfirmation confirmation={confirmation} />;
}

ConfirmSignIn.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
