<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\Owner;

/**
 * Records sensitive Super Admin actions. The acting admin comes from the
 * `admin` guard; names are snapshotted so history reads correctly even after
 * an admin or business is removed.
 */
class AdminAuditLogger
{
    public function log(string $action, ?Owner $owner = null, ?string $description = null, array $metadata = [], ?string $reason = null): AdminAuditLog
    {
        $admin = auth('admin')->user();

        return AdminAuditLog::create([
            'admin_id' => $admin?->id,
            'admin_name' => $admin?->name,
            'owner_id' => $owner?->id,
            'owner_name' => $owner ? ($owner->business_name ?: $owner->name) : null,
            'action' => $action,
            'description' => $description ? mb_substr($description, 0, 500) : null,
            'reason' => $reason ? mb_substr($reason, 0, 500) : null,
            'metadata' => $metadata ?: null,
            'ip_address' => request()?->ip(),
        ]);
    }
}
