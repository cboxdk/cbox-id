import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { LinkConfirmation, type LinkConfirmationContent } from '@/ui';

type Props = PageProps<{ confirmation: LinkConfirmationContent }>;

/** What an Admin Portal setup link opens — a button, never the spending itself. See `LinkConfirmation`. */
export default function OpenPortalSetup({ confirmation }: Props) {
    return <LinkConfirmation confirmation={confirmation} />;
}

OpenPortalSetup.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
