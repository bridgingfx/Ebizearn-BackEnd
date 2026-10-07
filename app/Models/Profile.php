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
        // SECURITY: system fields are intentionally NOT fillable —
        // 'kyc_status', 'kyc_verified_at', 'kyc_reviewed_by',
        // 'fraud_score', 'contributor_level', 'completed_tasks_count',
        // 'approval_rate' must be set explicitly in code, never via
        // mass assignment (prevents self-verification / rank boosting).
        'interests_json',
        'preferences_json',
        'kyc_document_type',
        'kyc_front_path',
        'kyc_back_path',
        'kyc_selfie_path',
        'kyc_submitted_at',
        'kyc_rejection_reason',
        'preferred_payout_method',
    ];

    /**
     * KYC documents sit on the private disk; their storage paths never leave
     * the API. Staff fetch them through the authenticated document endpoint.
     */
    protected $hidden = [
        'kyc_front_path',
        'kyc_back_path',
        'kyc_selfie_path',
    ];

    protected $appends = ['kyc_documents'];

    protected $casts = [
        'kyc_submitted_at' => 'datetime',
        'kyc_verified_at' => 'datetime',
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

    /**
     * Which KYC document sides are on file (front/back/selfie) — lets the
     * review UI know what to request without exposing storage paths.
     */
    protected function kycDocuments(): Attribute
    {
        return Attribute::get(fn () => array_values(array_filter([
            ($this->attributes['kyc_front_path'] ?? null) ? 'front' : null,
            ($this->attributes['kyc_back_path'] ?? null) ? 'back' : null,
            ($this->attributes['kyc_selfie_path'] ?? null) ? 'selfie' : null,
        ])));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
