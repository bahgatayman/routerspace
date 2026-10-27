<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\RoomImage;
use App\Models\SharedSession;
use App\Models\Workspace;
use App\Services\Admin\BusinessOverviewService;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Multiple bookable uses of one physical room (rooms.physical_room_id): each
 * use is independently configured, and all uses of a physical room share ONE
 * availability. Interval rule (unchanged): [start, end) — touching bounds are
 * not conflicts. Concurrency on MySQL: RoomUsesConcurrencyTest.
 */
class RoomUsesTest extends TestCase
{
    use RefreshDatabase;

    private Owner $owner;

    private Workspace $ws;

    private Room $shared;    // the physical room: "Shared Workspace", 6 seats

    private Room $training;  // another use: exclusive, 10 people

    private Room $studio;    // another use: exclusive

    private string $day = '2026-10-06';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00'));

        $this->owner = $this->makeOwner();
        $this->ws = Workspace::create(['owner_id' => $this->owner->id, 'name' => 'Main']);
        $this->shared = Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $this->ws->id, 'name' => 'Shared Workspace',
            'type' => 'shared', 'capacity' => 6, 'price_per_hour' => 150, 'description' => 'Hot desks']);
        $this->training = $this->use($this->shared, 'Training Room', 'training', 10, 400);
        $this->studio = $this->use($this->shared, 'Studio', 'studio', 4, 250);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ fixtures

    private function makeOwner(): Owner
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0,
            'is_active' => true, 'sort_order' => 1, 'features' => ['workspace', 'booking'],
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);
        $owner = Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now()->subDay(), 'subscription_expires_at' => now()->addMonth(),
        ]);
        foreach ($plan->features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    /** A use created directly (physical_room_id is not mass-assignable on purpose). */
    private function use(Room $physical, string $name, string $type, int $capacity, float $price, bool $active = true): Room
    {
        $use = new Room(['owner_id' => $physical->owner_id, 'workspace_id' => $physical->workspace_id, 'name' => $name, 'type' => $type,
            'capacity' => $capacity, 'price_per_hour' => $price, 'description' => "$name description", 'is_available' => $active]);
        $use->physical_room_id = $physical->id;
        $use->save();

        return $use;
    }

    private function member(?Owner $owner = null): HotspotUser
    {
        $owner ??= $this->owner;

        return HotspotUser::create(['owner_id' => $owner->id, 'name' => 'Member '.uniqid(), 'phone' => '010'.rand(10000000, 99999999), 'password' => 'pass1234']);
    }

    private function book(Room $room, string $start, string $end, int $party = 1, string $status = 'confirmed', ?string $date = null, float $price = 100): Booking
    {
        return Booking::create([
            'owner_id' => $room->owner_id, 'room_id' => $room->id, 'hotspot_user_id' => $this->member()->id, 'party_size' => $party,
            'booking_date' => $date ?? $this->day, 'start_time' => $start, 'end_time' => $end,
            'price_per_hour' => $price, 'total_hours' => 2, 'total_price' => $price * 2, 'status' => $status,
        ]);
    }

    private function postBooking(Room $room, string $start, string $end, int $party = 1)
    {
        return $this->actingAs($this->owner, 'owner')->post('/bookings', [
            'room_id' => $room->id, 'hotspot_user_id' => $this->member()->id, 'party_size' => $party,
            'booking_date' => $this->day, 'start_time' => $start, 'end_time' => $end,
        ]);
    }

    private function availability(): AvailabilityService
    {
        return app(AvailabilityService::class);
    }

    private function free(Room $room, string $start, string $end): int
    {
        return $this->availability()->availabilityForRange($room->fresh(), $this->day, $start, $end);
    }

    // ------------------------------------------------------------------ owner experience

    public function test_owner_adds_another_use_with_its_own_name_type_capacity_price_and_description(): void
    {
        $room = Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $this->ws->id, 'name' => 'Hall A', 'type' => 'meeting', 'capacity' => 8, 'price_per_hour' => 200]);

        $this->actingAs($this->owner, 'owner')
            ->get(route('rooms.uses.create', [$this->ws, $room]))
            ->assertOk()->assertSee(__('app.workspace.uses.add_title', ['room' => 'Hall A']))
            ->assertSee(__('app.workspace.uses.add_shares_hint', ['room' => 'Hall A']))
            ->assertSee(route('rooms.uses.store', [$this->ws, $room]), false);

        $this->actingAs($this->owner, 'owner')->post(route('rooms.uses.store', [$this->ws, $room]), [
            'name' => 'Yoga Studio', 'type' => 'studio', 'capacity' => 12, 'price_per_hour' => 320, 'description' => 'Mats included',
        ])->assertRedirect(route('workspaces.index', ['workspace' => $this->ws->id]))->assertSessionHas('success');

        $use = Room::where('name', 'Yoga Studio')->firstOrFail();
        $this->assertSame($room->id, $use->physical_room_id);
        $this->assertSame(['studio', 12, '320.00', 'Mats included', true], [$use->type, $use->capacity, $use->price_per_hour, $use->description, $use->is_available]);
        $this->assertSame($this->ws->id, $use->workspace_id);
        // The physical room itself is untouched.
        $this->assertSame(['meeting', 8, '200.00'], [$room->fresh()->type, $room->fresh()->capacity, $room->fresh()->price_per_hour]);
    }

    public function test_the_use_form_is_the_full_room_form_with_every_pricing_option(): void
    {
        $page = $this->actingAs($this->owner, 'owner')->get(route('rooms.uses.create', [$this->ws, $this->shared]))->assertOk();
        $normal = $this->actingAs($this->owner, 'owner')->get(route('rooms.create', $this->ws))->assertOk();

        foreach (['name="pricing_model"', 'value="duration"', 'value="people"', 'value="people_duration"', 'name="pricing_rules"',
            'name="plans"', 'name="pricing_profiles"', 'name="billing_unit"', 'name="billing_buffer_minutes"', 'name="price_per_hour"'] as $field) {
            $normal->assertSee($field, false);
            $page->assertSee($field, false);
        }
        $page->assertSee(__('app.pricing.section'))->assertSee(__('app.plans.section'))->assertSee(__('app.pricing_profiles.section'));
    }

    public function test_a_new_use_saves_rule_pricing_plans_and_profiles_through_the_room_pipeline(): void
    {
        $rules = ['durations' => [['minutes' => 60], ['minutes' => 120]], 'prices' => [[300, 550]]];

        $this->actingAs($this->owner, 'owner')->post(route('rooms.uses.store', [$this->ws, $this->shared]), [
            'name' => 'Event Hall', 'type' => 'meeting', 'capacity' => 30,
            'pricing_model' => 'duration', 'pricing_rules' => json_encode($rules),
            'plans' => json_encode([['name' => 'Half day', 'people' => 30, 'minutes' => 240, 'price' => 900]]),
            'pricing_profiles' => json_encode([['name' => 'Wedding', 'price_per_hour' => 1200]]),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $use = Room::where('name', 'Event Hall')->firstOrFail();
        $this->assertSame($this->shared->id, $use->physical_room_id);
        $this->assertSame('duration', $use->pricing_model);
        $this->assertSame([60, 120], array_column($use->pricing_rules['durations'], 'minutes'));
        $this->assertSame(['Half day'], $use->plans()->pluck('name')->all());
        $this->assertSame(['Wedding'], $use->pricingProfiles()->pluck('name')->all());

        // The physical room and the other uses gained nothing.
        foreach ([$this->shared, $this->training, $this->studio] as $room) {
            $this->assertSame(0, $room->plans()->count());
            $this->assertSame(0, $room->pricingProfiles()->count());
            $this->assertSame('hourly', $room->fresh()->pricing_model);
        }

        // Shared-room billing options apply to a shared use exactly as to a room.
        $this->actingAs($this->owner, 'owner')->post(route('rooms.uses.store', [$this->ws, $this->training]), [
            'name' => 'Cowork Corner', 'type' => 'shared', 'capacity' => 8, 'price_per_hour' => 60,
            'billing_unit' => 'hour', 'billing_buffer_minutes' => 10,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $corner = Room::where('name', 'Cowork Corner')->firstOrFail();
        $this->assertSame(['hour', 10, $this->shared->id], [$corner->billing_unit, (int) $corner->billing_buffer_minutes, $corner->physical_room_id]);
    }

    public function test_editing_one_use_never_changes_another_uses_pricing_or_settings(): void
    {
        $this->actingAs($this->owner, 'owner')->put(route('rooms.update', [$this->ws, $this->training]), [
            'name' => 'Training Room', 'type' => 'training', 'capacity' => 10, 'price_per_hour' => 400,
            'plans' => json_encode([['name' => 'Course day', 'people' => 10, 'full_day' => true, 'price' => 2500]]),
            'pricing_profiles' => json_encode([['name' => 'Corporate', 'price_per_hour' => 600]]),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $snapshot = fn (Room $r) => $r->fresh()->only(['name', 'type', 'capacity', 'price_per_hour', 'pricing_model', 'pricing_rules', 'description', 'is_available', 'physical_room_id']);
        $studio = $snapshot($this->studio);
        $shared = $snapshot($this->shared);

        // Re-price the training use with a rule model and change its plans/profiles.
        $this->actingAs($this->owner, 'owner')->put(route('rooms.update', [$this->ws, $this->training]), [
            'name' => 'Training Hall', 'type' => 'training', 'capacity' => 12,
            'pricing_model' => 'people', 'pricing_rules' => json_encode(['people' => [['max' => 6], ['max' => 12]], 'prices' => [[300], [500]]]),
            'plans' => json_encode([]), 'pricing_profiles' => json_encode([['name' => 'NGO', 'price_per_hour' => 200]]),
            'description' => 'Projector',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $training = $this->training->fresh();
        $this->assertSame(['Training Hall', 12, 'people', $this->shared->id], [$training->name, $training->capacity, $training->pricing_model, $training->physical_room_id]);
        $this->assertSame(0, $training->plans()->count());
        $this->assertSame(['NGO'], $training->pricingProfiles()->pluck('name')->all());
        $this->assertSame($studio, $snapshot($this->studio));
        $this->assertSame($shared, $snapshot($this->shared));
        $this->assertSame(0, $this->studio->plans()->count() + $this->studio->pricingProfiles()->count());
    }

    public function test_adding_a_use_from_a_use_attaches_it_to_the_physical_room_never_nested(): void
    {
        $this->actingAs($this->owner, 'owner')->get(route('rooms.uses.create', [$this->ws, $this->training]))->assertOk()
            ->assertSee(__('app.workspace.uses.add_title', ['room' => 'Shared Workspace']));

        $this->actingAs($this->owner, 'owner')->post(route('rooms.uses.store', [$this->ws, $this->training]), [
            'name' => 'Podcast Booth', 'type' => 'studio', 'capacity' => 2, 'price_per_hour' => 90,
        ])->assertRedirect();

        $this->assertSame($this->shared->id, Room::where('name', 'Podcast Booth')->value('physical_room_id'));
    }

    public function test_the_use_form_validates_like_the_room_form(): void
    {
        $this->actingAs($this->owner, 'owner')->from(route('rooms.uses.create', [$this->ws, $this->shared]))
            ->post(route('rooms.uses.store', [$this->ws, $this->shared]), ['name' => '', 'type' => 'castle', 'capacity' => 0, 'price_per_hour' => -5])
            ->assertSessionHasErrors(['name', 'type', 'capacity', 'price_per_hour']);
        $this->actingAs($this->owner, 'owner')->post(route('rooms.uses.store', [$this->ws, $this->shared]), [
            'name' => 'Pod', 'type' => 'meeting', 'capacity' => 2,
            'pricing_model' => 'duration', 'pricing_rules' => json_encode(['durations' => [['minutes' => 60]], 'prices' => [['']]]),
        ])->assertSessionHasErrors('pricing_rules');
        $this->assertSame(2, $this->shared->uses()->count());
    }

    public function test_rooms_grid_shows_every_use_as_its_own_card_with_its_own_details_and_photos(): void
    {
        RoomImage::create(['owner_id' => $this->owner->id, 'room_id' => $this->training->id, 'path' => 'room-images/training-cover.jpg', 'is_cover' => true, 'sort_order' => 1]);
        RoomImage::create(['owner_id' => $this->owner->id, 'room_id' => $this->shared->id, 'path' => 'room-images/shared-cover.jpg', 'is_cover' => true, 'sort_order' => 1]);
        $this->studio->update(['is_available' => false]);

        $res = $this->actingAs($this->owner, 'owner')->get(route('workspaces.index', ['workspace' => $this->ws->id]))->assertOk();

        $rooms = $res->viewData('rooms');
        $this->assertSame(['Shared Workspace', 'Studio', 'Training Room'], $rooms->pluck('name')->all(), 'Each use is its own card.');
        $this->assertSame(3, $res->viewData('roomStats')['total']);
        $this->assertSame(3, $res->viewData('workspaces')->first()->rooms_count, 'The workspace chip counts the cards shown.');
        $studioRoom = $rooms->firstWhere('name', 'Studio');
        $this->assertSame('unavailable', $studioRoom->statusKey($studioRoom->occupied_seats), 'An inactive use shows as unavailable, like any room.');

        $html = $res->getContent();
        $cards = $this->cards($html);
        $this->assertCount(3, $cards);

        $training = $cards[$this->training->id];
        $this->assertStringContainsString('Training Room', $training);
        $this->assertStringContainsString(__('app.room_type.training'), $training);
        $this->assertStringContainsString(trans_choice('app.workspace.seats_count', 10, ['count' => 10]), $training);
        $this->assertStringContainsString(e($this->training->fresh()->pricingSummary()), $training);
        $this->assertStringContainsString('training-cover.jpg', $training);
        $this->assertStringNotContainsString('shared-cover.jpg', $training, 'A use shows its own photos, never the room photos.');
        $this->assertStringContainsString(e(__('app.workspace.uses.shares_with', ['room' => 'Shared Workspace'])), $training);
        $this->assertStringContainsString('<b class="ls-room-shares-name"><bdi>Shared Workspace</bdi></b>', $training, 'The linked room is named in full, set apart.');
        $this->assertStringContainsString(route('rooms.edit', [$this->ws, $this->training]), $training);
        $this->assertStringContainsString(route('rooms.toggle', [$this->ws, $this->training]), $training);

        $studio = $cards[$this->studio->id];
        $this->assertStringContainsString(__('app.room_type.studio'), $studio);
        $this->assertStringContainsString(trans_choice('app.workspace.seats_count', 4, ['count' => 4]), $studio);
        $this->assertStringContainsString(e($this->studio->fresh()->pricingSummary()), $studio);
        $this->assertStringContainsString('ls-room-photo-placeholder', $studio, 'No photos of its own: the normal placeholder.');
        $this->assertStringContainsString(__('app.workspace.mark_available'), $studio);

        // The physical room names its uses in one line; the nested list is gone.
        $this->assertStringContainsString(e(__('app.workspace.uses.shares_with', ['room' => 'Studio, Training Room'])), $cards[$this->shared->id]);
        $this->assertStringContainsString('shared-cover.jpg', $cards[$this->shared->id]);
        $this->assertStringNotContainsString('ls-room-uses-block', $html);
        $this->assertStringNotContainsString('physical_room', $html, 'No database terms in the UI.');

        // "Add another use" is on every card's menu.
        foreach ([$this->shared, $this->training, $this->studio] as $room) {
            $this->assertStringContainsString(route('rooms.uses.create', [$this->ws, $room]), $cards[$room->id]);
        }
    }

    public function test_an_ordinary_room_card_has_no_shares_label(): void
    {
        $plain = Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $this->ws->id, 'name' => 'Board Room', 'type' => 'meeting', 'capacity' => 8, 'price_per_hour' => 200]);

        $html = $this->actingAs($this->owner, 'owner')->get(route('workspaces.index', ['workspace' => $this->ws->id]))->assertOk()->getContent();
        $card = $this->cards($html)[$plain->id];

        $this->assertStringContainsString('Board Room', $card);
        $this->assertStringNotContainsString('ls-room-shares', $card);
        $this->assertStringNotContainsString(e(__('app.workspace.uses.shares_with', ['room' => ''])), $card);
    }

    public function test_rooms_grid_filters_sort_and_occupancy_follow_the_shared_availability(): void
    {
        // Type filter: a use is matched by its own type.
        $res = $this->actingAs($this->owner, 'owner')->get(route('workspaces.index', ['workspace' => $this->ws->id, 'type' => 'studio']))->assertOk();
        $this->assertSame(['Studio'], $res->viewData('rooms')->pluck('name')->all());

        // A booking in progress on the Training use makes every card of the room occupied.
        Carbon::setTestNow(Carbon::parse($this->day.' 10:30:00'));
        $this->book($this->training, '10:00', '12:00');

        $res = $this->actingAs($this->owner, 'owner')->get(route('workspaces.index', ['workspace' => $this->ws->id, 'sort' => 'price']))->assertOk();
        $rooms = $res->viewData('rooms');
        $this->assertSame(['Shared Workspace', 'Studio', 'Training Room'], $rooms->pluck('name')->all(), 'Sorted by each card\'s own price.');
        foreach ($rooms as $room) {
            $this->assertSame('occupied', $room->statusKey($room->occupied_seats), "{$room->name} shares the booked room.");
        }
        $this->assertSame(3, $res->viewData('roomStats')['occupied']);
    }

    public function test_rooms_page_counts_physical_rooms_and_bookable_options_separately(): void
    {
        Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $this->ws->id, 'name' => 'Board Room', 'type' => 'meeting', 'capacity' => 8, 'price_per_hour' => 200]);
        $this->studio->update(['is_available' => false]);

        $res = $this->actingAs($this->owner, 'owner')->get(route('workspaces.index', ['workspace' => $this->ws->id]))->assertOk();
        $stats = $res->viewData('roomStats');

        $this->assertSame([4, 2, 1, 1], [$stats['total'], $stats['physical'], $stats['inactive'], $stats['unavailable']]);
        $res->assertSee(trans_choice('app.workspace.rooms_summary_physical', 2, ['count' => 2]))
            ->assertSee(trans_choice('app.workspace.rooms_summary_options', 4, ['count' => 4]))
            ->assertSee(__('app.workspace.rooms_summary_inactive', ['count' => 1]))
            ->assertSee(__('app.workspace.all_options'))
            ->assertSee('2 physical rooms · 4 bookable options · 1 inactive');

        // The plan's room limit still counts the 2 physical rooms only.
        $this->assertSame(2, $this->owner->fresh()->physicalRoomsCount());

        // A type filter narrows both numbers to what is shown.
        $stats = $this->actingAs($this->owner, 'owner')->get(route('workspaces.index', ['workspace' => $this->ws->id, 'type' => 'studio']))->viewData('roomStats');
        $this->assertSame([1, 1], [$stats['total'], $stats['physical']]);
    }

    public function test_the_shares_label_escapes_room_names(): void
    {
        $this->shared->update(['name' => '<img src=x onerror=alert(1)>']);

        $html = $this->actingAs($this->owner, 'owner')->get(route('workspaces.index', ['workspace' => $this->ws->id]))->assertOk()->getContent();

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('<b class="ls-room-shares-name"><bdi>&lt;img src=x onerror=alert(1)&gt;</bdi></b>', $this->cards($html)[$this->training->id]);
    }

    public function test_a_workspace_without_uses_keeps_the_plain_room_count(): void
    {
        $owner = $this->makeOwner();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Solo']);
        foreach (['A', 'B'] as $name) {
            Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => "Room $name", 'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 100]);
        }

        $res = $this->actingAs($owner, 'owner')->get(route('workspaces.index'))->assertOk();
        $this->assertSame([2, 2], [$res->viewData('roomStats')['total'], $res->viewData('roomStats')['physical']]);
        $res->assertSee(__('app.workspace.all_rooms'))
            ->assertSee(__('app.workspace.rooms_subtitle', ['workspace' => 'Solo']))
            ->assertDontSee(__('app.workspace.all_options'))
            ->assertDontSee(trans_choice('app.workspace.rooms_summary_physical', 2, ['count' => 2]));
    }

    public function test_admin_business_counts_use_physical_rooms(): void
    {
        $summary = app(BusinessOverviewService::class)->summary($this->owner);
        $this->assertSame(1, $summary['rooms'], 'One physical room offered as three options.');

        $admin = Admin::create(['name' => 'Admin', 'email' => 'a'.uniqid().'@t.local', 'password' => 'secret123']);
        $owners = $this->actingAs($admin, 'admin')->get(route('admin.workspaces.index'))->assertOk()->viewData('owners');
        $this->assertSame(1, (int) $owners->firstWhere('id', $this->owner->id)->rooms_count);
    }

    public function test_uses_of_one_room_sit_together_without_breaking_the_sort(): void
    {
        Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $this->ws->id, 'name' => 'Sun Room', 'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 250]);
        Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $this->ws->id, 'name' => 'Annex', 'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 900]);
        $names = fn (array $query) => $this->actingAs($this->owner, 'owner')
            ->get(route('workspaces.index', ['workspace' => $this->ws->id] + $query))->viewData('rooms')->pluck('name')->all();

        // By name: each physical room, then its own uses ("Sun Room" no longer splits them).
        $this->assertSame(['Annex', 'Shared Workspace', 'Studio', 'Training Room', 'Sun Room'], $names([]));
        // By price: price decides; the 250 tie keeps the use next to its room's group.
        $this->assertSame(['Shared Workspace', 'Studio', 'Sun Room', 'Training Room', 'Annex'], $names(['sort' => 'price']));
        // Status filter still applies.
        $this->training->update(['is_available' => false]);
        $this->assertSame(['Training Room'], $names(['status' => 'unavailable']));
    }

    public function test_photos_uploaded_to_one_use_stay_with_that_use(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner, 'owner')->post(route('rooms.images.store', [$this->ws, $this->training]), [
            'images' => [UploadedFile::fake()->image('t.jpg', 800, 600)],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(1, $this->training->images()->count());
        $this->assertSame(0, $this->shared->images()->count());
        $this->assertSame(0, $this->studio->images()->count());
    }

    public function test_a_checked_in_session_holds_the_other_uses_until_its_booked_end_and_all_day_once_late(): void
    {
        $today = now()->toDateString();
        $booking = $this->book($this->shared, '08:00', '10:00', date: $today);

        $this->actingAs($this->owner, 'owner')->post("/bookings/{$booking->id}/check-in", ['party_size' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('checked_in', $booking->fresh()->status);

        // Within its booked time: the other uses are taken until 10:00, free after it.
        $this->assertSame(0, $this->availability()->availabilityForRange($this->training->fresh(), $today, '09:00', '10:00'));
        $this->assertSame(1, $this->availability()->availabilityForRange($this->training->fresh(), $today, '11:00', '12:00'));

        // Still open past 10:00: its end is unknown, so the rest of the day is taken.
        Carbon::setTestNow(Carbon::parse($today.' 10:05:00'));
        $this->assertSame(0, $this->availability()->availabilityForRange($this->training->fresh(), $today, '11:00', '12:00'));
        $this->actingAs($this->owner, 'owner')->post('/bookings', [
            'room_id' => $this->training->id, 'hotspot_user_id' => $this->member()->id, 'party_size' => 1,
            'booking_date' => $today, 'start_time' => '11:00', 'end_time' => '12:00',
        ]);
        $this->assertSame(0, Booking::where('room_id', $this->training->id)->count());

        // Closed: free again.
        SharedSession::where('booking_id', $booking->id)->update(['status' => 'closed']);
        $this->assertSame(1, $this->availability()->availabilityForRange($this->training->fresh(), $today, '11:00', '12:00'));
    }

    /**
     * Each room card's HTML, keyed by room id.
     *
     * @return array<int, string>
     */
    private function cards(string $html): array
    {
        preg_match_all('/<article class="ls-room .*?<\/article>/s', $html, $m);
        $cards = [];
        foreach ($m[0] as $card) {
            preg_match('/id="room-(\d+)-name"/', $card, $id);
            $cards[(int) $id[1]] = $card;
        }

        return $cards;
    }

    public function test_room_editor_explains_the_link_both_ways(): void
    {
        $this->actingAs($this->owner, 'owner')->get(route('rooms.edit', [$this->ws, $this->training]))->assertOk()
            ->assertSee(__('app.workspace.uses.this_is_a_use', ['room' => 'Shared Workspace']));
        $this->actingAs($this->owner, 'owner')->get(route('rooms.edit', [$this->ws, $this->shared]))->assertOk()
            ->assertSee(__('app.workspace.uses.title'))->assertSee('Training Room')->assertSee('Studio');
    }

    public function test_uses_are_configured_and_switched_on_or_off_independently(): void
    {
        $this->assertSame(['150.00', '400.00', '250.00'], [$this->shared->price_per_hour, $this->training->price_per_hour, $this->studio->price_per_hour]);
        $this->assertSame([6, 10, 4], [$this->shared->capacity, $this->training->capacity, $this->studio->capacity]);
        $this->assertNotSame($this->training->description, $this->studio->description);

        $this->actingAs($this->owner, 'owner')->post(route('rooms.toggle', [$this->ws, $this->training]))->assertRedirect();

        $this->assertFalse($this->training->fresh()->is_available);
        $this->assertTrue($this->shared->fresh()->is_available);
        $this->assertTrue($this->studio->fresh()->is_available);
    }

    // ------------------------------------------------------------------ shared availability

    public function test_a_booking_of_one_use_blocks_overlapping_times_for_every_other_use(): void
    {
        $this->book($this->training, '14:00', '16:00');

        // Exact, partial (start inside, end inside, enclosing) overlaps: unavailable on both other uses.
        foreach ([['14:00', '16:00'], ['15:00', '17:00'], ['13:00', '14:30'], ['13:00', '17:00'], ['14:30', '15:00']] as [$s, $e]) {
            $this->assertSame(0, $this->free($this->shared, $s, $e), "Shared Workspace $s-$e");
            $this->assertSame(0, $this->free($this->studio, $s, $e), "Studio $s-$e");
        }
        // Adjacent intervals stay bookable (the app's [start, end) rule).
        $this->assertSame(6, $this->free($this->shared, '16:00', '17:00'));
        $this->assertSame(1, $this->free($this->studio, '12:00', '14:00'));
        // A cancelled booking holds nothing, as before.
        Booking::where('room_id', $this->training->id)->update(['status' => 'cancelled']);
        $this->assertSame(6, $this->free($this->shared, '14:00', '16:00'));
    }

    public function test_booking_requests_on_another_use_are_refused_for_overlaps_and_accepted_when_adjacent(): void
    {
        $this->postBooking($this->training, '14:00', '16:00')->assertRedirect()->assertSessionMissing('error');
        $this->assertSame(1, Booking::where('room_id', $this->training->id)->count());

        $this->postBooking($this->studio, '15:00', '17:00')->assertRedirect()->assertSessionHas('error');
        $this->postBooking($this->shared, '13:00', '14:30')->assertRedirect()->assertSessionHas('error');
        $this->assertSame(0, Booking::whereIn('room_id', [$this->studio->id, $this->shared->id])->count());

        $this->postBooking($this->studio, '16:00', '17:00')->assertRedirect()->assertSessionMissing('error');
        $this->postBooking($this->shared, '12:00', '14:00')->assertRedirect()->assertSessionMissing('error');
        $this->assertSame(1, Booking::where('room_id', $this->studio->id)->count());
        $this->assertSame(1, Booking::where('room_id', $this->shared->id)->count());
    }

    public function test_a_shared_use_keeps_its_seats_but_any_seat_blocks_the_other_uses(): void
    {
        $this->postBooking($this->shared, '10:00', '12:00', 2)->assertSessionMissing('error');
        $this->postBooking($this->shared, '10:00', '12:00', 4)->assertSessionMissing('error');
        $this->postBooking($this->shared, '10:00', '12:00', 1)->assertSessionHas('error'); // 7 > 6 seats
        $this->assertSame(2, Booking::where('room_id', $this->shared->id)->count());

        $this->postBooking($this->training, '11:00', '13:00')->assertSessionHas('error');
        $this->assertSame(0, Booking::where('room_id', $this->training->id)->count());
    }

    public function test_an_open_walk_in_on_the_shared_use_blocks_the_other_uses_and_vice_versa(): void
    {
        $today = now()->toDateString();
        SharedSession::create(['owner_id' => $this->owner->id, 'room_id' => $this->shared->id, 'hotspot_user_id' => $this->member()->id,
            'party_size' => 1, 'session_date' => $today, 'start_time' => '07:30:00', 'opened_at' => now()->subMinutes(30), 'status' => 'open']);

        $this->assertSame(0, $this->availability()->availableNow($this->training->fresh()));
        $this->assertSame(0, $this->availability()->availabilityForRange($this->training->fresh(), $today, '09:00', '10:00'));
        // Before the walk-in started, the other uses were free.
        $this->assertSame(1, $this->availability()->availabilityForRange($this->training->fresh(), $today, '06:00', '07:30'));

        SharedSession::query()->update(['status' => 'closed']);
        $this->book($this->training, '07:00', '09:00', date: $today);
        $this->assertSame(0, $this->availability()->availableNow($this->shared->fresh()));
        $this->assertTrue($this->availability()->otherUsesOccupiedNow($this->shared->fresh()));
    }

    public function test_calendar_and_availability_report_show_time_held_by_another_use(): void
    {
        $this->book($this->training, '14:00', '16:00');

        $blocks = collect($this->availability()->freeBusyForDay($this->studio->fresh(), $this->day));
        $held = $blocks->first(fn ($b) => $b['start'] === '14:00');
        $this->assertTrue($held['other_use']);
        $this->assertSame(0, $held['available']);

        $slots = collect($this->availability()->bookableSlots($this->studio->fresh(), $this->day));
        $this->assertFalse($slots->firstWhere('start', '14:00')['available']);
        $this->assertTrue($slots->firstWhere('start', '16:00')['available']);

        $report = $this->availability()->rangeReport(collect([$this->shared->fresh()]), $this->day, $this->day, '15:00', '17:00');
        $this->assertSame('unavailable', $report[0]['days'][0]['status']);
        $this->assertSame('Training Room', collect($report[0]['days'][0]['conflicts'])->firstWhere('other_use', '!==', null)['other_use']);
    }

    // ------------------------------------------------------------------ lifecycle

    public function test_deactivating_a_use_keeps_its_bookings_and_their_time_stays_blocked(): void
    {
        $kept = $this->book($this->training, '14:00', '16:00', price: 400);

        $this->actingAs($this->owner, 'owner')->post(route('rooms.toggle', [$this->ws, $this->training]))->assertRedirect();

        $this->assertSame(['confirmed', '800.00'], [$kept->fresh()->status, $kept->fresh()->total_price]);
        $this->assertSame(0, $this->free($this->shared, '14:00', '16:00'), 'A switched-off use still holds what it booked.');
        $this->assertSame(0, $this->free($this->studio, '15:00', '16:00'));
    }

    // ------------------------------------------------------------------ plan limit

    public function test_the_plan_room_limit_counts_physical_rooms_only(): void
    {
        $this->owner->plan->update(['max_rooms' => 1]); // the one physical room (with 2 uses) fills it

        $this->assertSame(1, $this->owner->fresh()->physicalRoomsCount());
        $this->assertSame(0, $this->owner->fresh()->remainingRoomSlots());

        // Another use is not a new room: still allowed at the limit.
        $this->actingAs($this->owner->fresh(), 'owner')->post(route('rooms.uses.store', [$this->ws, $this->shared]), [
            'name' => 'Podcast Booth', 'type' => 'studio', 'capacity' => 2, 'price_per_hour' => 90,
        ])->assertRedirect()->assertSessionMissing('error');
        $this->assertSame(3, $this->shared->uses()->count());

        // A new physical room is refused.
        $this->actingAs($this->owner->fresh(), 'owner')->post(route('rooms.store', $this->ws), [
            'name' => 'Second Hall', 'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 100,
        ])->assertRedirect()->assertSessionHas('error', __('app.plan_limit.rooms'));
        $this->assertSame(0, Room::where('name', 'Second Hall')->count());
    }

    // ------------------------------------------------------------------ walk-ins & open sessions

    private function walkIn(Room $room, string $start = '08:00', int $party = 1)
    {
        return $this->actingAs($this->owner, 'owner')->post(route('shared-sessions.store'), [
            'room_id' => $room->id, 'hotspot_user_id' => $this->member()->id,
            'session_date' => now()->toDateString(), 'start_time' => $start, 'party_size' => $party,
        ]);
    }

    private function openSession(Room $room)
    {
        return $this->actingAs($this->owner, 'owner')->post('/bookings', [
            'duration_type' => 'open', 'room_id' => $room->id, 'hotspot_user_id' => $this->member()->id,
        ]);
    }

    public function test_an_inactive_use_takes_no_new_walk_ins_while_an_active_use_of_the_same_room_does(): void
    {
        $hotDesks = $this->use($this->shared, 'Hot Desks', 'shared', 4, 60);
        $this->shared->update(['is_available' => false]);

        $this->walkIn($this->shared)->assertRedirect()->assertSessionHas('error', __('app.booking.room_disabled'));
        $this->assertSame(0, SharedSession::where('room_id', $this->shared->id)->count());

        $this->walkIn($hotDesks)->assertRedirect()->assertSessionMissing('error');
        $this->assertSame(1, SharedSession::where('room_id', $hotDesks->id)->where('status', 'open')->count());

        // Pickers only offer active rooms/uses.
        $picker = $this->actingAs($this->owner, 'owner')->get(route('active-sessions.index'))->viewData('sharedRooms')->pluck('name')->all();
        $this->assertContains('Hot Desks', $picker);
        $this->assertNotContains('Shared Workspace', $picker);
        $create = $this->actingAs($this->owner, 'owner')->get(route('active-sessions.create'))->assertOk()->viewData('sharedRooms')->pluck('name')->all();
        $this->assertSame(['Hot Desks'], $create);
    }

    public function test_a_walk_in_may_not_run_into_a_later_booking_of_another_use_today(): void
    {
        $today = now()->toDateString(); // 08:00
        $this->book($this->training, '16:00', '18:00', date: $today);

        $this->walkIn($this->shared, '08:00')->assertRedirect()
            ->assertSessionHas('error', __('app.booking.open_ended_conflict', ['room' => 'Training Room', 'time' => '16:00']));
        $this->assertSame(0, SharedSession::count());
    }

    public function test_a_walk_in_is_fine_once_the_other_uses_bookings_are_over_or_cancelled(): void
    {
        $today = now()->toDateString(); // 08:00
        $this->book($this->training, '06:00', '07:30', date: $today);            // already over
        $this->book($this->studio, '16:00', '18:00', status: 'cancelled', date: $today);
        $this->book($this->training, '10:00', '12:00');                            // tomorrow

        $this->walkIn($this->shared, '08:00')->assertRedirect()->assertSessionMissing('error');
        $this->assertSame(1, SharedSession::where('status', 'open')->count());
    }

    public function test_a_shared_walk_in_still_shares_seats_with_its_own_uses_later_bookings(): void
    {
        $this->book($this->shared, '16:00', '18:00', party: 2, date: now()->toDateString());

        $this->walkIn($this->shared, '08:00', party: 2)->assertRedirect()->assertSessionMissing('error');
        $this->assertSame(1, SharedSession::where('status', 'open')->count());
    }

    public function test_an_open_session_may_not_run_into_a_later_booking_of_its_room_or_another_use(): void
    {
        $today = now()->toDateString();

        $this->book($this->studio, '16:00', '18:00', date: $today);
        $this->openSession($this->training)->assertRedirect()
            ->assertSessionHas('error', __('app.booking.open_ended_conflict', ['room' => 'Studio', 'time' => '16:00']));

        Booking::query()->delete();
        $this->book($this->training, '19:00', '20:00', date: $today);
        $this->openSession($this->training)->assertRedirect()
            ->assertSessionHas('error', __('app.booking.open_ended_conflict', ['room' => 'Training Room', 'time' => '19:00']));
        $this->assertSame(0, Booking::where('status', 'open')->count());

        // Same rule for an ordinary room with no other uses.
        $plain = Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $this->ws->id, 'name' => 'Plain', 'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 50]);
        $this->book($plain, '15:00', '16:00', date: $today);
        $this->openSession($plain)->assertSessionHas('error', __('app.booking.open_ended_conflict', ['room' => 'Plain', 'time' => '15:00']));
    }

    public function test_an_open_session_is_still_allowed_when_the_rest_of_the_day_is_free(): void
    {
        $today = now()->toDateString();
        $this->book($this->studio, '06:00', '07:00', date: $today);   // over
        $this->book($this->studio, '16:00', '18:00');                 // tomorrow

        $this->openSession($this->training)->assertRedirect()->assertSessionMissing('error');
        $this->assertSame(1, Booking::where('room_id', $this->training->id)->where('status', 'open')->count());
        // …and it now holds the whole physical room for the rest of the day.
        $this->assertSame(0, $this->availability()->availabilityForRange($this->studio->fresh(), $today, '20:00', '21:00'));
    }

    public function test_editing_a_use_never_rewrites_existing_bookings(): void
    {
        $booking = $this->book($this->training, '14:00', '16:00', price: 400);

        $this->actingAs($this->owner, 'owner')->put(route('rooms.update', [$this->ws, $this->training]), [
            'name' => 'Training Hall', 'type' => 'training', 'capacity' => 6, 'price_per_hour' => 999,
        ])->assertRedirect()->assertSessionMissing('error');

        $this->assertSame('999.00', $this->training->fresh()->price_per_hour);
        $booking->refresh();
        $this->assertSame(['400.00', '800.00', $this->training->id], [$booking->price_per_hour, $booking->total_price, $booking->room_id]);
    }

    public function test_another_uses_bookings_never_stop_a_use_from_lowering_its_capacity(): void
    {
        $this->book($this->training, '14:00', '16:00');

        $this->actingAs($this->owner, 'owner')->put(route('rooms.update', [$this->ws, $this->shared]), [
            'name' => 'Shared Workspace', 'type' => 'shared', 'capacity' => 3, 'price_per_hour' => 150,
        ])->assertRedirect()->assertSessionMissing('error');

        $this->assertSame(3, $this->shared->fresh()->capacity);
    }

    public function test_deleting_is_safe_for_history_and_for_the_physical_room(): void
    {
        $this->book($this->training, '14:00', '16:00');

        $this->actingAs($this->owner, 'owner')->delete(route('rooms.destroy', [$this->ws, $this->training]))
            ->assertRedirect()->assertSessionHas('error', __('app.workspace.uses.delete_use_has_history'));
        $this->actingAs($this->owner, 'owner')->delete(route('rooms.destroy', [$this->ws, $this->shared]))
            ->assertRedirect()->assertSessionHas('error', __('app.workspace.uses.delete_room_has_uses'));
        $this->assertNotNull($this->training->fresh());
        $this->assertNotNull($this->shared->fresh());
        $this->assertSame(1, Booking::count());

        // Below the app guard, the database refuses too: a physical room can't be
        // deleted from under its uses (ON DELETE RESTRICT), so no cascade can reach
        // the uses' bookings.
        try {
            Room::whereKey($this->shared->id)->delete();
            $this->fail('Deleting a physical room that has uses must be refused by the database.');
        } catch (QueryException) {
        }
        $this->assertNotNull($this->shared->fresh());
        $this->assertSame(1, Booking::count());

        // A use with no history can be removed.
        $this->actingAs($this->owner, 'owner')->delete(route('rooms.destroy', [$this->ws, $this->studio]))->assertRedirect()->assertSessionMissing('error');
        $this->assertNull($this->studio->fresh());
    }

    // ------------------------------------------------------------------ isolation & compatibility

    public function test_another_owner_cannot_see_or_add_uses_of_my_room(): void
    {
        $other = $this->makeOwner();

        $this->actingAs($other, 'owner')->get(route('rooms.uses.create', [$this->ws, $this->shared]))->assertNotFound();
        $this->actingAs($other, 'owner')->post(route('rooms.uses.store', [$this->ws, $this->shared]), [
            'name' => 'Hijack', 'type' => 'studio', 'capacity' => 1, 'price_per_hour' => 1,
        ])->assertNotFound();

        // Even with their own workspace id in the URL, my room is not theirs.
        $theirWs = Workspace::create(['owner_id' => $other->id, 'name' => 'Theirs']);
        $this->actingAs($other, 'owner')->post(route('rooms.uses.store', [$theirWs, $this->shared]), [
            'name' => 'Hijack', 'type' => 'studio', 'capacity' => 1, 'price_per_hour' => 1,
        ])->assertNotFound();
        $this->assertSame(0, Room::where('name', 'Hijack')->count());
    }

    public function test_rooms_without_uses_behave_exactly_as_before(): void
    {
        $plain = Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $this->ws->id, 'name' => 'Plain', 'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 50]);
        $this->assertSame([$plain->id], $plain->inventoryRoomIds());
        $this->assertSame([], $plain->otherUseIds());

        // Another physical room's bookings never affect it.
        $this->book($this->training, '14:00', '16:00');
        $this->assertSame(1, $this->free($plain, '14:00', '16:00'));
        $this->book($plain, '14:00', '16:00');
        $this->assertSame(0, $this->free($plain, '15:00', '17:00'));
        $this->assertSame(1, $this->free($plain, '16:00', '17:00'));
        $this->assertSame($plain->id, Room::lockInventory($plain->id)->id);
        $this->assertSame($this->training->id, Room::lockInventory($this->training->id)->id);
    }
}
