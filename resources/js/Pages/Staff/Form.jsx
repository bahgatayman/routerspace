import { Link, router, useForm } from '@inertiajs/react';
import { ConfirmButton, Password } from '../../Components/ui';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';
import PermissionGrid from './PermissionGrid';

const inputCls = 'w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500';
const labelCls = 'block text-sm font-medium text-gray-700 mb-1';

/** Create + edit staff member (staff/create, staff/{id}/edit). */
export default function StaffForm({ staff, roles, permissionGroups, granted }) {
    const editing = !!staff;
    const title = t(editing ? 'staff.edit_staff' : 'staff.add_staff');
    usePageTitle(title);

    const form = useForm({
        name: staff?.name ?? '',
        email: staff?.email ?? '',
        password: '',
        role_id: staff?.role_id ? String(staff.role_id) : '',
        permissions: granted,
    });
    const errors = Object.values(form.errors);

    // Picking a role replaces the checked boxes with that role's bundle (same as the old inline script).
    const pickRole = (roleId) => {
        const role = roles.find((r) => String(r.id) === roleId);
        const keys = new Set(role ? role.permission_keys : []);
        const ids = permissionGroups.flatMap((g) => g.items).filter((p) => keys.has(p.key)).map((p) => p.id);
        form.setData((d) => ({ ...d, role_id: roleId, permissions: ids }));
    };

    const submit = (e) => {
        e.preventDefault();
        if (editing) form.put(`/staff/${staff.id}`);
        else form.post('/staff');
    };

    const fields = (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label htmlFor="staff-name" className={labelCls}>{t('staff.name')}</label>
                <input id="staff-name" type="text" name="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required className={inputCls} />
            </div>
            <div>
                <label htmlFor="staff-email" className={labelCls}>{t('staff.email')}</label>
                <input id="staff-email" type="email" name="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required className={inputCls} />
            </div>
            <div>
                <label htmlFor="staff-password" className={labelCls}>{t('staff.password')}</label>
                <Password name="password" id="staff-password" required={!editing} minLength={8} className={inputCls}
                    aria-describedby={editing ? 'staff-password-hint' : undefined}
                    value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                {editing && <p className="text-xs text-gray-500 mt-1" id="staff-password-hint">{t('staff.password_hint')}</p>}
            </div>
            <div>
                <label htmlFor="role-select" className={labelCls}>{t('staff.role')}</label>
                <select name="role_id" id="role-select" value={form.data.role_id} onChange={(e) => pickRole(e.target.value)} className={inputCls}>
                    <option value="">{t('staff.no_role')}</option>
                    {roles.map((r) => <option key={r.id} value={String(r.id)}>{r.label}</option>)}
                </select>
                <p className="text-xs text-gray-500 mt-1">{t('staff.role_hint')}</p>
            </div>
        </div>
    );

    const actions = (
        <div className="flex gap-3">
            <button type="submit" disabled={form.processing} className="bg-blue-600 text-white px-5 py-2.5 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm disabled:opacity-60">
                {t('staff.save')}
            </button>
            <Link href="/staff" className="px-5 py-2.5 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100 transition">{t('common.cancel')}</Link>
        </div>
    );

    const grid = <PermissionGrid groups={permissionGroups} value={form.data.permissions} onChange={(ids) => form.setData('permissions', ids)} />;

    return (
        <>
            <div className="flex items-center justify-between mb-6">
                <h1 className="text-2xl font-bold text-gray-900">{title}</h1>
                <Link href="/staff" className="text-sm text-gray-500 hover:text-gray-700">&larr; {t('common.back')}</Link>
            </div>

            {errors.length > 0 && (
                <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
                    <ul className="list-disc list-inside text-sm">{errors.map((e, i) => <li key={i}>{e}</li>)}</ul>
                </div>
            )}

            {!editing ? (
                <form onSubmit={submit} className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 max-w-3xl space-y-6">
                    {fields}
                    <div>
                        <p className="block text-sm font-medium text-gray-700 mb-2">{t('staff.permissions')}</p>
                        {grid}
                    </div>
                    {actions}
                </form>
            ) : (
                <div className="flex flex-col lg:flex-row gap-6 max-w-5xl">
                    <form onSubmit={submit} className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex-1 space-y-6">
                        {fields}
                        <div>
                            <div className="flex items-center justify-between mb-2">
                                <p className="block text-sm font-medium text-gray-700">{t('staff.permissions')}</p>
                                <button type="button" onClick={() => router.post(`/staff/${staff.id}/reset-permissions`, {}, {
                                        preserveScroll: true,
                                        // The form keeps its own state across the reload: take the freshly reset grants.
                                        onSuccess: (page) => form.setData('permissions', page.props.granted || []),
                                    })}
                                    className="text-xs text-blue-600 hover:underline">
                                    {t('staff.reset_to_role_defaults')}
                                </button>
                            </div>
                            {grid}
                        </div>
                        {actions}
                    </form>

                    <div className="w-full lg:w-64 shrink-0 space-y-3">
                        <Link href={`/staff/${staff.id}/activity`} className="block bg-white rounded-xl shadow-sm border border-gray-100 p-4 text-sm font-medium text-blue-600 hover:bg-blue-50 transition text-center">
                            {t('staff.view_activity')}
                        </Link>

                        {staff.is_active ? (
                            <ConfirmButton href={`/staff/${staff.id}/toggle-status`} method="post" message={t('staff.confirm_disable')} confirmLabel={t('staff.disable')}
                                variant="secondary" size={null} className="w-full !justify-center bg-white rounded-xl shadow-sm border border-gray-100 p-4 text-sm font-medium text-amber-600 hover:bg-amber-50 transition">
                                {t('staff.disable')}
                            </ConfirmButton>
                        ) : (
                            <button type="button" onClick={() => router.post(`/staff/${staff.id}/toggle-status`, {}, { preserveScroll: true })}
                                className="w-full bg-white rounded-xl shadow-sm border border-gray-100 p-4 text-sm font-medium text-green-600 hover:bg-green-50 transition">
                                {t('staff.enable')}
                            </button>
                        )}

                        <ConfirmButton href={`/staff/${staff.id}`} method="delete" message={t('staff.confirm_delete')} confirmLabel={t('common.delete')}
                            variant="secondary" size={null} className="w-full !justify-center bg-white rounded-xl shadow-sm border border-gray-100 p-4 text-sm font-medium text-red-600 hover:bg-red-50 transition">
                            {t('common.delete')}
                        </ConfirmButton>
                    </div>
                </div>
            )}
        </>
    );
}
