import { EmptyState, Pagination } from '../../../Components/ui';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';
import BusinessHeader from './Header';

export default function BusinessAudit({ business, logs }) {
    usePageTitle(`${t('admin_biz.tabs.audit')} · ${business.name}`);

    return (
        <div className="ls-biz-page">
            <BusinessHeader business={business} active="audit" />

            <section className="ls-card ls-biz-panel">
                <div className="ls-card-head"><h2 className="ls-card-title">{t('admin_biz.audit_title')}</h2></div>
                {logs.data.length === 0 ? (
                    <EmptyState title={t('admin_biz.audit_empty')} />
                ) : (
                    <>
                        <div className="ls-table-wrap">
                            <table className="ls-table">
                                <thead>
                                    <tr>
                                        <th>{t('admin_biz.col_when')}</th>
                                        <th>{t('admin_biz.col_admin')}</th>
                                        <th>{t('admin_biz.col_action')}</th>
                                        <th>{t('admin_biz.col_reason')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {logs.data.map((log) => (
                                        <tr key={log.id}>
                                            <td className="ls-nowrap">{log.when}</td>
                                            <td>{log.admin}</td>
                                            <td>{log.action}</td>
                                            <td>{log.reason}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="ls-biz-pager"><Pagination paginator={logs} /></div>
                    </>
                )}
            </section>
        </div>
    );
}
