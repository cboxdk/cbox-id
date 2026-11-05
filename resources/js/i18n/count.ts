import { useTranslator } from './index';

const keys = {
    member: 'console.count_member_count_members_96bf8a19',
    project: 'console.count_project_count_projects_d6f688d0',
    environment: 'console.count_environment_count_environments_0c9d19d5',
    workspace: 'console.count_workspace_count_workspaces_ca9575fd',
    organization: 'console.count_organization_count_organizations_8ac09581',
    connection: 'console.count_connection_count_connections_c63bb722',
    integration: 'console.count_integration_count_integrations_f5b734ac',
} as const;

export function useConsoleCount() {
    const { choice, locale } = useTranslator();
    return (count: number, noun: keyof typeof keys): string =>
        choice(keys[noun], count, { count: count.toLocaleString(locale) });
}
