import { useEffect, useRef } from 'react';
import { t } from '../../lib/i18n';

/** "Select all in group" checkbox — checked when all, indeterminate when some. */
function GroupToggle({ checked, total, onChange }) {
    const ref = useRef(null);
    useEffect(() => {
        if (ref.current) ref.current.indeterminate = checked > 0 && checked < total;
    }, [checked, total]);
    return <input ref={ref} type="checkbox" checked={checked > 0 && checked === total} onChange={(e) => onChange(e.target.checked)} />;
}

/**
 * Port of staff/_permission-grid + its inline script. Every group renders
 * unconditionally — selecting a role only bulk-checks boxes (in the form),
 * it never hides a group. `value` is the array of granted permission ids.
 */
export default function PermissionGrid({ groups, value, onChange }) {
    const selected = new Set(value);

    const toggleOne = (id, on) => {
        const next = new Set(selected);
        if (on) next.add(id); else next.delete(id);
        onChange([...next]);
    };
    const toggleGroup = (ids, on) => {
        const next = new Set(selected);
        ids.forEach((id) => (on ? next.add(id) : next.delete(id)));
        onChange([...next]);
    };

    return (
        <div className="ls-permission-grid" id="permission-grid">
            {groups.map((group) => {
                const ids = group.items.map((p) => p.id);
                const checked = ids.filter((id) => selected.has(id)).length;
                return (
                    <div key={group.key} className="ls-permission-group" data-permission-group>
                        <div className="ls-permission-group-head">
                            <span className="ls-permission-group-title">
                                {group.label}{' '}
                                <span className="ls-permission-group-count" data-group-count>{checked}/{ids.length}</span>
                            </span>
                            <label className="ls-permission-group-all">
                                <GroupToggle checked={checked} total={ids.length} onChange={(on) => toggleGroup(ids, on)} />
                                {' '}{t('staff.select_all_in_group')}
                            </label>
                        </div>
                        <div className="ls-permission-items">
                            {group.items.map((p) => (
                                <label key={p.id} className="ls-permission-item">
                                    <input type="checkbox" name="permissions[]" value={p.id} data-permission-key={p.key} data-permission-item
                                        checked={selected.has(p.id)} onChange={(e) => toggleOne(p.id, e.target.checked)} />
                                    <span>{p.label}</span>
                                </label>
                            ))}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
