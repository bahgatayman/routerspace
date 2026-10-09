import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Session flash → the same feedback the Blade pages give: success/info/status
 * as a toast (window.LS.toast from public/js/panel.js); error/warning are
 * returned for the layout to show as banners; permission_denied opens the
 * layout's access-denied dialog. Fires once per server response (partial
 * reloads keep the previous flash object, so nothing repeats).
 */
export default function useFlash({ toast: useToast = true } = {}) {
    const { flash = {} } = usePage().props;

    useEffect(() => {
        const toast = window.LS && window.LS.toast;
        if (!toast || !useToast) return;
        ['success', 'info', 'status'].forEach((k) => flash[k] && toast(flash[k], { tone: 'ok' }));
    }, [flash]);

    return flash;
}
