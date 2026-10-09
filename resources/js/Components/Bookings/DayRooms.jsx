import { Link } from '@inertiajs/react';
import { t } from '../../lib/i18n';

/*
 * Per-room booked/available timeline + that day's bookings (React port of
 * partials/booking-day-rooms). Shared by the calendar's day view and each
 * day of the week view. The blocks/slots come straight from
 * AvailabilityService — this only lays them out on one time scale.
 *
 * Click-to-book pills sit on the SAME entryStart/entryEnd scale as the bar
 * above them, inside one overflow-x-auto rail (56px per hour, min 320px),
 * positioned with inset-inline-start so they flip with RTL like the bar does.
 * Each pill is a plain link to /bookings/create (the quick-booking modal
 * intercepts it; store() always re-validates capacity server-side).
 */
const TYPE_COLORS = { blue: 'bg-blue-100 text-blue-700', purple: 'bg-purple-100 text-purple-700', green: 'bg-green-100 text-green-700', orange: 'bg-orange-100 text-orange-700', gray: 'bg-gray-100 text-gray-700' };

const toMinutes = (time) => {
    if (time.startsWith('24:00')) return 24 * 60;
    const [h, m] = time.split(':').map((n) => parseInt(n, 10));
    return h * 60 + m;
};

function RoomEntry({ entry }) {
    const { room, blocks, slots = [], bookings, live } = entry;
    const entryStart = blocks.length ? toMinutes(blocks[0].start) : 0;
    const entryEnd = blocks.length ? toMinutes(blocks[blocks.length - 1].end) : 0;
    const total = entryEnd - entryStart;
    const railMinWidth = total > 0 ? Math.max(320, (total / 60) * 56) : 320;

    return (
        <div className="border border-gray-100 rounded-lg p-4">
            <div className="flex flex-wrap items-center justify-between gap-2 mb-3">
                <div className="flex items-center gap-2">
                    <span className="font-medium text-gray-900">{room.workspace} / {room.name}</span>
                    <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium ${TYPE_COLORS[room.type_color] || 'bg-gray-100 text-gray-700'}`}>{room.type_label}</span>
                </div>
                {live && (
                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-teal-100 text-teal-800">
                        {t('booking.live_now', { occupied: live.occupied, capacity: live.capacity })}
                    </span>
                )}
            </div>

            <div className="overflow-x-auto pb-1 mb-3">
                <div style={{ minWidth: railMinWidth }}>
                    <div className="flex w-full h-3 rounded overflow-hidden border border-gray-100">
                        {blocks.map((block, i) => {
                            const width = total > 0 ? Math.max(1, ((toMinutes(block.end) - toMinutes(block.start)) / total) * 100) : 0;
                            const closed = block.closed ?? false;
                            const color = closed ? 'bg-gray-200' : block.used === 0 ? 'bg-green-200' : block.available > 0 ? 'bg-yellow-300' : 'bg-red-300';
                            const tooltip = closed
                                ? `${block.start}–${block.end}: ${t('settings.working_hours.closed')}`
                                : `${block.start}–${block.end}: ${block.used}/${block.capacity} ${t('session.occupied')}`;
                            return <div key={i} className={`${color} h-full`} style={{ width: `${width}%` }} title={tooltip} />;
                        })}
                    </div>

                    {slots.length > 0 && (
                        <div className="relative w-full h-6 mt-1.5">
                            {slots.map((slot) => {
                                const s = toMinutes(slot.start);
                                const e = toMinutes(slot.end);
                                const left = total > 0 ? ((s - entryStart) / total) * 100 : 0;
                                const width = total > 0 ? ((e - s) / total) * 100 : 0;
                                const style = { insetInlineStart: `${left}%`, width: `calc(${width}% - 2px)` };
                                return slot.available ? (
                                    <a key={slot.start} href={slot.create_url} style={style} title={`${t('booking.book_this_slot')} — ${slot.label}`}
                                        className="absolute top-0 h-full flex items-center justify-center rounded text-[10px] font-medium bg-green-100 text-green-700 hover:bg-green-600 hover:text-white transition overflow-hidden">
                                        <span className="truncate px-0.5">{slot.label}</span>
                                    </a>
                                ) : (
                                    <span key={slot.start} style={style} title={slot.label}
                                        className="absolute top-0 h-full flex items-center justify-center rounded text-[10px] font-medium bg-gray-100 text-gray-400 cursor-not-allowed overflow-hidden">
                                        <span className="truncate px-0.5">{slot.label}</span>
                                    </span>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>

            {bookings.length === 0 ? (
                <p className="text-xs text-gray-400">{t('empty.no_bookings_on_day')}</p>
            ) : (
                <div className="space-y-1">
                    {bookings.map((b) => (
                        <Link key={b.id} href={`/bookings/${b.id}`} className="flex items-center justify-between gap-2 px-2 py-1.5 rounded hover:bg-gray-50 text-sm transition">
                            <span className="font-medium text-gray-700 whitespace-nowrap">{b.time_range}</span>
                            <span className="text-gray-600 flex-1 truncate px-2">
                                {b.customer}
                                {b.party_size > 1 && <span className="text-gray-400 text-xs"> &middot; {t('session.party_of', { count: b.party_size })}</span>}
                            </span>
                            <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium ${b.status_class}`}>{b.status_label}</span>
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function DayRooms({ dayRooms }) {
    return (
        <div className="space-y-3">
            {dayRooms.length === 0 && <p className="text-sm text-gray-400 text-center py-6">{t('empty.no_rooms_for_calendar')}</p>}
            {dayRooms.map((entry) => <RoomEntry key={`${entry.room.id}-${entry.date}`} entry={entry} />)}
        </div>
    );
}
