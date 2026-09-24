import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { LinkConfirmation, type LinkConfirmationContent } from '@/ui';

type Props = PageProps<{ confirmation: LinkConfirmationContent }>;

/** What an invitation to join an organization opens — a button, never the spending itself. See `LinkConfirmation`. */
export default function JoinOrganization({ confirmation }: Props) {
    return <LinkConfirmation confirmation={confirmation} />;
}

JoinOrganization.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
