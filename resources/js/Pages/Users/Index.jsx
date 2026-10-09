import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ConfirmButton, Icon, Pagination } from '../../Components/ui';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

/** Whole-row navigation (the name cell also holds a real link for keyboard / ctrl+click). */
function rowClick(e, href) {
    if (e.target.closest('a, button, form, input, select, label, .ls-overlay') || window.getSelection().toString()) return;
    if (e.metaKey || e.ctrlKey) window.open(href, '_blank', 'noopener');
    else router.visit(href);
}

function rowAuxClick(e, href) {
    if (e.button !== 1 || e.target.closest('a, button, form, input, select, label, .ls-overlay')) return;
    e.preventDefault();
    window.open(href, '_blank', 'noopener');
}

/**
 * Members list with live search: typing debounces (~300ms) into an Inertia
 * partial reload of just `users` + `search`, keeping ?search= in the URL.
 * Ranking and the <mark> highlighting are done server-side
 * (MemberSearchService + App\Support\Highlight, HTML already escaped).
 */
export default function UsersIndex({ users, search, hasHotspot }) {
    const title = hasHotspot ? t('user.hotspot_users') : t('common.members');
    usePageTitle(title);
    const [term, setTerm] = useState(search || '');
    const [busy, setBusy] = useState(false);
    const timer = useRef(null);
    const inputRef = useRef(null);

    useEffect(() => () => clearTimeout(timer.current), []);

    // A new search always starts back at page 1.
    const load = (value) => {
        const q = value.trim();
        router.get('/users', q ? { search: q } : {}, {
            only: ['users', 'search'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
        });
    };

    const onInput = (e) => {
        const value = e.target.value;
        setTerm(value);
        clearTimeout(timer.current);
        timer.current = setTimeout(() => load(value), 300);
    };

    const clear = () => {
        setTerm('');
        clearTimeout(timer.current);
        load('');
        inputRef.current?.focus();
    };

    const rows = users.data;

    return (
        <>
            <div className="flex items-center justify-between mb-6">
                <h1 className="text-2xl font-bold text-gray-900">{title}</h1>
                <Link href="/users/create" className="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                    {t('btn.add_user')}
                </Link>
            </div>

            <div className="mb-6 max-w-md relative" id="user-search-wrap">
                <input ref={inputRef} type="text" id="user-search-input" value={term} onChange={onInput} placeholder={t('user.search_placeholder')}
                    autoComplete="off"
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 pe-9 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
                <button type="button" id="user-search-clear" aria-label={t('user.clear_search')} onClick={clear} hidden={term.length === 0}
                    className="absolute inset-y-0 end-0 flex items-center px-3 text-gray-400 hover:text-gray-600">
                    <Icon name="x" className="w-4 h-4" />
                </button>
            </div>

            <div id="user-table-wrap" aria-live="polite" aria-busy={busy || undefined}>
                {rows.length > 0 ? (
                    <>
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
                            <table className="w-full text-sm text-left">
                                <thead className="bg-gray-50 text-gray-500 uppercase text-xs tracking-wider">
                                    <tr>
                                        <th className="px-4 py-3">{t('table.th.name')}</th>
                                        <th className="px-4 py-3">{t('table.th.phone')}</th>
                                        {hasHotspot && <th className="px-4 py-3">{t('table.th.download')}</th>}
                                        {hasHotspot && <th className="px-4 py-3">{t('table.th.upload')}</th>}
                                        <th className="px-4 py-3">{t('table.th.status')}</th>
                                        <th className="px-4 py-3">{t('table.th.created')}</th>
                                        <th className="px-4 py-3">{t('table.th.actions')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {rows.map((user) => {
                                        const href = `/users/${user.id}`;
                                        return (
                                            <tr key={user.id} className="row-link hover:bg-gray-50 transition cursor-pointer" data-href={href}
                                                onClick={(e) => rowClick(e, href)} onAuxClick={(e) => rowAuxClick(e, href)}>
                                                <td className="px-4 py-3 font-medium text-gray-900">
                                                    <Link href={href} className="hover:text-blue-600" dangerouslySetInnerHTML={{ __html: user.name_html }} />
                                                    {user.package && (
                                                        <div>
                                                            <span className={`ls-pkg-pill ${user.package.soon ? 'is-soon' : ''}`} title={user.package.title}>
                                                                <Icon name="clock" />{user.package.text}
                                                            </span>
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3"><bdi dir="ltr" dangerouslySetInnerHTML={{ __html: user.phone_html }} /></td>
                                                {hasHotspot && <td className="px-4 py-3">{user.speed_download}</td>}
                                                {hasHotspot && <td className="px-4 py-3">{user.speed_upload}</td>}
                                                <td className="px-4 py-3">
                                                    {user.status === 'active'
                                                        ? <span className="bg-green-100 text-green-700 px-2 py-1 rounded-full text-xs font-medium">{t('status.active')}</span>
                                                        : <span className="bg-red-100 text-red-700 px-2 py-1 rounded-full text-xs font-medium">{t('status.inactive')}</span>}
                                                </td>
                                                <td className="px-4 py-3 text-gray-500">{user.created}</td>
                                                <td className="px-4 py-3 flex gap-2">
                                                    <Link href={`/users/${user.id}/edit`} className="text-blue-600 hover:underline text-sm font-medium">{t('common.edit')}</Link>
                                                    <ConfirmButton href={href} method="delete" message="Delete this user?" confirmLabel={t('common.delete')}
                                                        variant="ghost" className="text-red-600 hover:underline text-sm font-medium">
                                                        {t('common.delete')}
                                                    </ConfirmButton>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        <div className="mt-4"><Pagination paginator={users} /></div>
                    </>
                ) : (
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center">
                        <p className="text-gray-500 text-lg">{search !== '' ? t('user.no_match') : t('empty.no_users')}</p>
                    </div>
                )}
            </div>
        </>
    );
}
