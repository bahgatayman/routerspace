/*
 * React + Inertia entry. Laravel controllers return Inertia::render('Area/Page', props);
 * the matching resources/js/Pages/Area/Page.jsx is loaded on demand (code-split).
 * Owner pages get the persistent OwnerLayout, /admin pages AdminLayout, unless a
 * page sets its own `layout` (auth pages, error page).
 */
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import AdminLayout from './Layouts/AdminLayout';
import OwnerLayout from './Layouts/OwnerLayout';

const pages = import.meta.glob('./Pages/**/*.jsx');

createInertiaApp({
    title: (title) => title || 'Link Space Panel',
    resolve: async (name) => {
        const importer = pages[`./Pages/${name}.jsx`];
        if (!importer) throw new Error(`Unknown page: ${name}`);
        const page = (await importer()).default;
        if (page.layout === undefined) {
            page.layout = name.startsWith('Admin/')
                ? (child) => <AdminLayout>{child}</AdminLayout>
                : (child) => <OwnerLayout>{child}</OwnerLayout>;
        }
        return page;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#3f68af', delay: 150 },
});
