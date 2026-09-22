<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Profile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'avatar_url',
        'phone',
        'country_code',
        'city',
        'language',
        'bio',
        'contributor_level',
        'fraud_score',
        'completed_tasks_count',
        'approval_rate',
        'interests_json',
        'preferences_json',
    ];

    protected $casts = [
        'interests_json' => 'array',
        'preferences_json' => 'array',
        'approval_rate' => 'float',
        'completed_tasks_count' => 'integer',
        'fraud_score' => 'integer',
    ];

    /**
     * Uploaded avatars are stored as a disk-relative path; expose them as a full URL.
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn (?string $value) => $value && str_starts_with($value, 'avatars/')
            ? Storage::disk('public')->url($value)
            : $value);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
