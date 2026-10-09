import { usePage } from '@inertiajs/react';

/** Red "all validation errors" box — same as the Blade `@if ($errors->any())` block. */
export default function ErrorSummary({ errors, className = 'mb-4' }) {
    const shared = usePage().props.errors || {};
    const list = Object.values(errors || shared).filter(Boolean);
    if (list.length === 0) return null;
    return (
        <div className={`bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg ${className}`}>
            {list.map((e, i) => <p key={i} className="text-sm">{e}</p>)}
        </div>
    );
}
