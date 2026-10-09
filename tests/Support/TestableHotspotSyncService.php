<?php

namespace Tests\Support;

use App\Models\Owner;
use App\Services\HotspotSyncService;
use App\Services\MikroTikService;

/** Swaps HotspotSyncService's real MikroTikService for a FakeMikroTikService — see FakeMikroTikService's own docblock for why. */
class TestableHotspotSyncService extends HotspotSyncService
{
    public FakeMikroTikService $fake;

    public function __construct()
    {
        $this->fake = new FakeMikroTikService;
    }

    protected function client(Owner $owner): MikroTikService
    {
        return $this->fake;
    }
}
