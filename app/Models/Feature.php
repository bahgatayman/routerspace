<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Feature extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'icon',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(Owner::class, 'owner_features')
            ->withPivot('enabled_at')
            ->withTimestamps();
    }

    public static function findByKey(string $key): ?self
    {
        return static::where('key', $key)->where('is_active', true)->first();
    }
}
