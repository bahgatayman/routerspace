import MemberForm, { PlanUsage } from '../../Components/Users/MemberForm';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

/** Full-page version of the add-member form (direct links / new tab). The Users page uses a pop-up. */
export default function UsersCreate({ hasHotspot, plan }) {
    usePageTitle(t('user.add_new_user'));

    return (
        <>
            <PlanUsage plan={plan} className="mb-6 max-w-lg mx-auto" />
            <div className="max-w-lg mx-auto bg-white rounded-xl shadow-sm border border-gray-100 p-8">
                <h2 className="text-xl font-semibold text-gray-900 mb-6">{t('user.add_new_user')}</h2>
                {/* On success the server redirects to the Users list. */}
                <MemberForm hasHotspot={hasHotspot} idPrefix="page" />
            </div>
        </>
    );
}
