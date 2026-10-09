import WorkspaceForm from '../../Components/Workspaces/WorkspaceForm';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

export default function WorkspacesCreate() {
    usePageTitle(t('workspace.create_workspace'));
    return (
        <WorkspaceForm title={t('workspace.create_workspace')} submitLabel={t('btn.create_workspace')}
            action="/workspaces" method="post" backUrl="/workspaces" />
    );
}
