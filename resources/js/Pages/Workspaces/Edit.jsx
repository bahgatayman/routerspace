import WorkspaceForm from '../../Components/Workspaces/WorkspaceForm';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

export default function WorkspacesEdit({ workspace }) {
    usePageTitle(t('workspace.edit_workspace'));
    return (
        <WorkspaceForm workspace={workspace} title={t('workspace.edit_workspace')} submitLabel={t('btn.update_workspace')}
            action={`/workspaces/${workspace.id}`} method="put" backUrl={`/workspaces?workspace=${workspace.id}`} />
    );
}
