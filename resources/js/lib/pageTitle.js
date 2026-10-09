import { useEffect, useSyncExternalStore } from 'react';

/*
 * Page title + breadcrumb for the persistent layouts. Pages call
 * usePageTitle(title, parent?) and the layout (which never remounts during
 * client-side navigation) reads it with useLayoutTitle().
 */
let state = { title: '', parent: null };
const listeners = new Set();

export function usePageTitle(title, parent = null) {
    useEffect(() => {
        state = { title, parent };
        listeners.forEach((l) => l());
    }, [title, parent]);
}

export function useLayoutTitle() {
    return useSyncExternalStore(
        (cb) => { listeners.add(cb); return () => listeners.delete(cb); },
        () => state,
        () => state,
    );
}
