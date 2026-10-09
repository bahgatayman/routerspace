<?php

namespace Tests\Feature;

use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Live search on the Users page: debounced client-side into an Inertia
 * partial reload (only `users` + `search`), server-ranked
 * (MemberSearchService — name/phone/email, case-insensitive, partial, with a
 * bounded fuzzy fallback for name typos), highlighted with App\Support\Highlight.
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

    /** The rendered (highlighted) name + phone cells of every row on the page, as one string. */
    private function rowsHtml(TestResponse $res): string
    {
        return collect($res->inertiaProps('users.data'))
            ->map(fn (array $row) => $row['name_html'].' | <bdi dir="ltr">'.$row['phone_html'].'</bdi>')
            ->implode("\n");
    }

    private function search(Owner $owner, string $query): TestResponse
    {
        return $this->actingAs($owner, 'owner')->get('/users'.$query)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Users/Index'));
    }

    public function test_exact_and_case_insensitive_name_search_finds_the_member(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Bahgat Ayman', '01000000000');
        $this->member($owner, 'Someone Else', '01011111111');

        foreach (['Bahgat', 'bahgat', 'BAHGAT', 'bahga'] as $term) {
            $html = $this->rowsHtml($this->search($owner, '?search='.$term));
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

        $html = $this->rowsHtml($this->search($owner, '?search=bahgt'));

        $this->assertStringContainsString('Bahgat Ayman', $html);
        $this->assertStringNotContainsString('Completely Unrelated', $html);
    }

    public function test_fuzzy_matching_is_not_too_aggressive_for_unrelated_names(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Zoe Clarkson', '01000000000');

        // A short, generic term with no real similarity must not drag in
        // unrelated rows just because the dataset is small — the page then
        // shows its "no match" state (no rows + a non-empty search).
        $this->search($owner, '?search=xqz')->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 0)
            ->where('search', 'xqz'));
    }

    public function test_partial_phone_search_matches_a_substring_anywhere_in_the_number(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Member One', '01123456789');
        $this->member($owner, 'Member Two', '01299999999');

        $html = $this->rowsHtml($this->search($owner, '?search=0112'));

        $this->assertStringContainsString('Member One', $html);
        $this->assertStringNotContainsString('Member Two', $html);
    }

    public function test_partial_email_search_matches_even_though_email_is_not_a_table_column(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Member One', '01000000000', 'bahgat@example.com');
        $this->member($owner, 'Member Two', '01011111111', 'other@example.com');

        $html = $this->rowsHtml($this->search($owner, '?search=bahgat@'));

        $this->assertStringContainsString('Member One', $html);
        $this->assertStringNotContainsString('Member Two', $html);
    }

    public function test_matching_text_is_highlighted(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Bahgat Ayman', '01123456789');

        $html = $this->rowsHtml($this->search($owner, '?search=bahgat'));
        $this->assertStringContainsString('<mark class="ls-hl">Bahgat</mark>', $html);

        $html = $this->rowsHtml($this->search($owner, '?search=0112'));
        $this->assertStringContainsString('<mark class="ls-hl">0112</mark>', $html);
    }

    public function test_highlighted_fields_are_html_escaped(): void
    {
        $owner = $this->owner();
        $this->member($owner, '<b>Bahgat</b>', '01123456789');

        $html = $this->rowsHtml($this->search($owner, '?search=bahgat'));

        // The page renders these cells as HTML, so only Highlight's own <mark> may be raw.
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
    }

    public function test_fuzzy_only_match_gets_the_subtle_whole_field_highlight(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Bahgat Ayman', '01123456789');

        $html = $this->rowsHtml($this->search($owner, '?search=bahgt'));

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

        $html = $this->rowsHtml($this->search($owner, '?search='));

        $this->assertStringContainsString('Alpha', $html);
        $this->assertStringContainsString('Beta', $html);
    }

    public function test_live_search_partial_reload_returns_only_the_list_props(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Alpha', '01000000001');
        $this->member($owner, 'Beta', '01000000002');

        // What the debounced search box sends: a partial reload of just
        // `users` + `search`, with ?search= kept in the URL.
        $this->search($owner, '?search=alph')->assertInertia(fn (Assert $page) => $page
            ->reloadOnly(['users', 'search'], fn (Assert $reload) => $reload
                ->missing('hasHotspot')
                ->where('search', 'alph')
                ->has('users.data', 1)
                ->where('users.data.0.name_html', '<mark class="ls-hl">Alph</mark>a')));
    }

    public function test_search_box_has_no_submit_button(): void
    {
        // The widget is rendered by React now (Users/Index), so check its source.
        $jsx = file_get_contents(resource_path('js/Pages/Users/Index.jsx'));

        $start = strpos($jsx, 'id="user-search-wrap"');
        $end = strpos($jsx, 'id="user-table-wrap"');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $searchWidget = substr($jsx, $start, $end - $start);

        $this->assertStringNotContainsString('type="submit"', $searchWidget);
        $this->assertStringNotContainsString('<form', $searchWidget);
    }

    public function test_pagination_links_keep_the_search_query(): void
    {
        $owner = $this->owner();
        for ($i = 0; $i < 20; $i++) {
            $this->member($owner, 'Person '.$i, '0101'.str_pad((string) $i, 7, '0', STR_PAD_LEFT));
        }

        $next = $this->search($owner, '?search=person')->inertiaProps('users.next_page_url');

        $this->assertNotNull($next);
        $this->assertStringContainsString('search=person', $next);
    }

    public function test_search_is_tenant_scoped(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $this->member($owner, 'Bahgat Mine', '01000000001');
        $this->member($other, 'Bahgat Theirs', '01000000002');

        $res = $this->search($owner, '?search=bahgat');

        $this->assertStringContainsString('Mine', $this->rowsHtml($res));
        $this->assertStringNotContainsString('Theirs', json_encode($res->inertiaProps()));
    }

    public function test_an_out_of_range_page_for_a_narrowed_search_falls_back_to_page_one(): void
    {
        $owner = $this->owner();
        $this->member($owner, 'Bahgat Ayman', '01000000000');
        // Only one match for this search term — page 4 doesn't exist.
        for ($i = 0; $i < 20; $i++) {
            $this->member($owner, 'Other Person '.$i, '0101'.str_pad((string) $i, 7, '0', STR_PAD_LEFT));
        }

        $html = $this->rowsHtml($this->search($owner, '?search=bahgat&page=4'));

        $this->assertStringContainsString('Ayman', $html);
    }

    public function test_existing_table_columns_and_actions_are_unaffected(): void
    {
        $owner = $this->owner();
        $owner->enableFeature('hotspot');
        $member = $this->member($owner, 'Alpha', '01000000001');

        $this->search($owner->fresh(), '')->assertInertia(fn (Assert $page) => $page
            ->where('hasHotspot', true)
            ->where('users.data.0.id', $member->id)
            ->where('users.data.0.status', 'active')
            ->where('users.data.0.speed_download', '10M')
            ->where('users.data.0.speed_upload', '5M'));
    }
}
