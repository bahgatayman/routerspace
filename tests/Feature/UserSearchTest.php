<?php

namespace Tests\Feature;

use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Live search on the Users page: debounced client-side, server-ranked
 * (MemberSearchService — name/phone/email, case-insensitive, partial, with a
 * bounded fuzzy fallback for name typos), rendered with App\Support\Highlight.
 */
class UserSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        Plan::create([
            'name' => 'Basic', 'slug' => 'basic', 'max_members' => 100,
            'price_per_month' => 0, 'is_active' => true, 'sort_order' => 1,
        ]);
    }

    private function owner(): Owner
    {
        $owner = Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'password',
            'business_name' => 'Space', 'plan_id' => Plan::first()->id, 'is_active' => true,
            'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ]);
        // /users sits behind feature:hotspot,booking (either satisfies it).
        $owner->enableFeature('booking');

        return $owner->fresh();
    }

    private function member(Owner $owner, string $name, string $phone, ?string $email = null): HotspotUser
    {
        return HotspotUser::create([
            'owner_id' => $owner->id, 'name' => $name, 'phone' => $phone, 'password' => $phone,
            'email' => $email, 'speed_download' => '10M', 'speed_upload' => '5M', 'status' => 'active',
        ]);
    }

    public function test_exact_and_case_insensitive_name_search_finds_the_member(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Bahgat Ayman', '01000000000');
        $this->member($owner, 'Someone Else', '01011111111');

        foreach (['Bahgat', 'bahgat', 'BAHGAT', 'bahga'] as $term) {
            $html = $this->actingAs($owner, 'owner')->get('/users?search='.$term)->assertOk()->getContent();
            // The matched portion is wrapped in <mark>, so "Ayman" (the
            // untouched remainder) is the reliable contiguous marker here.
            $this->assertStringContainsString('Ayman', $html, "Expected a match for \"{$term}\"");
            $this->assertStringContainsString('ls-hl', $html, "Expected a highlighted match for \"{$term}\"");
            $this->assertStringNotContainsString('Someone Else', $html, "\"{$term}\" should not also match an unrelated name");
        }
    }

    public function test_small_typo_still_finds_the_intended_member(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Bahgat Ayman', '01000000000');
        $this->member($owner, 'Completely Unrelated', '01099999999');

        $html = $this->actingAs($owner, 'owner')->get('/users?search=bahgt')->assertOk()->getContent();

        $this->assertStringContainsString('Bahgat Ayman', $html);
        $this->assertStringNotContainsString('Completely Unrelated', $html);
    }

    public function test_fuzzy_matching_is_not_too_aggressive_for_unrelated_names(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Zoe Clarkson', '01000000000');

        // A short, generic term with no real similarity must not drag in
        // unrelated rows just because the dataset is small.
        $html = $this->actingAs($owner, 'owner')->get('/users?search=xqz')->assertOk()->getContent();

        $this->assertStringContainsString(__('app.user.no_match'), $html);
    }

    public function test_partial_phone_search_matches_a_substring_anywhere_in_the_number(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Member One', '01123456789');
        $this->member($owner, 'Member Two', '01299999999');

        $html = $this->actingAs($owner, 'owner')->get('/users?search=0112')->assertOk()->getContent();

        $this->assertStringContainsString('Member One', $html);
        $this->assertStringNotContainsString('Member Two', $html);
    }

    public function test_partial_email_search_matches_even_though_email_is_not_a_table_column(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Member One', '01000000000', 'bahgat@example.com');
        $this->member($owner, 'Member Two', '01011111111', 'other@example.com');

        $html = $this->actingAs($owner, 'owner')->get('/users?search=bahgat@')->assertOk()->getContent();

        $this->assertStringContainsString('Member One', $html);
        $this->assertStringNotContainsString('Member Two', $html);
    }

    public function test_matching_text_is_highlighted(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Bahgat Ayman', '01123456789');

        $html = $this->actingAs($owner, 'owner')->get('/users?search=bahgat')->assertOk()->getContent();
        $this->assertStringContainsString('<mark class="ls-hl">Bahgat</mark>', $html);

        $html = $this->actingAs($owner, 'owner')->get('/users?search=0112')->assertOk()->getContent();
        $this->assertStringContainsString('<mark class="ls-hl">0112</mark>', $html);
    }

    public function test_fuzzy_only_match_gets_the_subtle_whole_field_highlight(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Bahgat Ayman', '01123456789');

        $html = $this->actingAs($owner, 'owner')->get('/users?search=bahgt')->assertOk()->getContent();

        $this->assertStringContainsString('<mark class="ls-hl ls-hl--fuzzy">Bahgat Ayman</mark>', $html);
        // The row matched via the (fuzzy) name only — the phone had nothing
        // to do with it and must render plain, not also marked "fuzzy" just
        // because the search term happened to be non-empty.
        $this->assertStringContainsString('<bdi dir="ltr">01123456789</bdi>', $html);
    }

    public function test_empty_search_restores_the_full_list(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Alpha', '01000000001');
        $this->member($owner, 'Beta', '01000000002');

        $html = $this->actingAs($owner, 'owner')->get('/users?search=')->assertOk()->getContent();

        $this->assertStringContainsString('Alpha', $html);
        $this->assertStringContainsString('Beta', $html);
    }

    public function test_ajax_request_returns_only_the_table_partial(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Alpha', '01000000001');

        $full = $this->actingAs($owner, 'owner')->get('/users')->getContent();
        $partial = $this->actingAs($owner, 'owner')
            ->get('/users', ['X-Requested-With' => 'XMLHttpRequest'])
            ->getContent();

        $this->assertStringContainsString('<html', $full);
        $this->assertStringNotContainsString('<html', $partial);
        $this->assertStringContainsString('Alpha', $partial);
    }

    public function test_search_box_has_no_submit_button(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Alpha', '01000000001');

        $html = $this->actingAs($owner, 'owner')->get('/users')->getContent();

        $start = strpos($html, 'id="user-search-wrap"');
        $end = strpos($html, 'id="user-table-wrap"');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $searchWidget = substr($html, $start, $end - $start);

        $this->assertStringNotContainsString('type="submit"', $searchWidget);
    }

    public function test_an_out_of_range_page_for_a_narrowed_search_falls_back_to_page_one(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Bahgat Ayman', '01000000000');
        // Only one match for this search term — page 4 doesn't exist.
        for ($i = 0; $i < 20; $i++) {
            $this->member($owner, 'Other Person '.$i, '0101'.str_pad((string) $i, 7, '0', STR_PAD_LEFT));
        }

        $html = $this->actingAs($owner, 'owner')->get('/users?search=bahgat&page=4')->assertOk()->getContent();

        $this->assertStringContainsString('Ayman', $html);
    }

    public function test_existing_table_columns_and_actions_are_unaffected(): void
    {
        $owner = $this->owner();
        $owner->enableFeature('hotspot');
        $member = $this->member($owner, 'Alpha', '01000000001');

        $html = $this->actingAs($owner->fresh(), 'owner')->get('/users')->assertOk()->getContent();

        $this->assertStringContainsString('/users/'.$member->id.'/edit', $html);
        $this->assertStringContainsString('/users/'.$member->id, $html);
        $this->assertStringContainsString(__('app.status.active'), $html);
    }
}
