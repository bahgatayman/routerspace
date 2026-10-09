import { Head } from '@inertiajs/react';
import { Button, Icon } from '../Components/ui';
import { t } from '../lib/i18n';

/** Shown for client-side visits that fail (403/404/429/500/503). Full page loads use the Blade errors/* pages. */
export default function Error({ status }) {
    const key = [403, 404, 429, 500, 503].includes(status) ? status : 500;
    return (
        <>
            <Head title={t(`error.${key}_title`)} />
            <div className="ls-page" style={{ display: 'grid', placeItems: 'center', minHeight: '60vh' }}>
                <div style={{ textAlign: 'center', display: 'grid', gap: 12, justifyItems: 'center', maxWidth: 420 }}>
                    <span className="ls-icon-circle ls-icon-circle--danger"><Icon name={key === 403 ? 'lock' : 'alert'} /></span>
                    <p className="ls-eyebrow" style={{ margin: 0 }}>{key}</p>
                    <h1 className="ls-title" style={{ justifyContent: 'center' }}>{t(`error.${key}_heading`)}</h1>
                    <p className="ls-subtitle" style={{ marginTop: 0 }}>{t(`error.${key}_message`)}</p>
                    <div className="ls-actions" style={{ justifyContent: 'center' }}>
                        <Button variant="secondary" onClick={() => window.history.back()}>{t('common.back')}</Button>
                        <Button variant="primary" href="/dashboard">{t('nav.dashboard')}</Button>
                    </div>
                </div>
            </div>
        </>
    );
}

Error.layout = (page) => page;
