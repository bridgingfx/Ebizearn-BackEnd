<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'email',
        'password',
        'role',
        'status',
        'referral_code',
        'referrer_id',
        'email_verified_at',
        // Round 2: SHA-256 digest of the pending verification token + issue
        // timestamp. The raw token is never persisted.
        'email_verification_token',
        'email_verification_sent_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // Round 2: the token digest is internal; clients get email_verified.
        'email_verification_token',
        'email_verification_sent_at',
    ];

    /**
     * Round 2: `email_verified` is part of every serialized user (incl. the
     * /me response) while email_verified_at stays the source of truth.
     */
    protected $appends = ['email_verified'];

    // NOTE: Laravel 10.50 does not support the model `casts()` method form
    // (Laravel 11+ only), so casts are declared as a property.
    protected $casts = [
        'email_verified_at' => 'datetime',
        'email_verification_sent_at' => 'datetime',
        'password' => 'hashed',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }
            if (empty($user->referral_code)) {
                $user->referral_code = strtoupper(Str::random(8));
            }
        });
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function business(): HasOne
    {
        return $this->hasOne(Business::class, 'owner_id');
    }

    public function taskAssignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(TaskSubmission::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class);
    }

    /**
     * Direct per-user permission grants assigned by Super Admin.
     */
    public function directPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_user')->withTimestamps();
    }

    /**
     * Sync the user's direct permission grants (Super Admin only, via API).
     *
     * @param string[] $permissionNames
     */
    public function syncPermissions(array $permissionNames): void
    {
        $ids = Permission::whereIn('name', $permissionNames)->pluck('id')->all();
        $this->directPermissions()->sync($ids);
    }

    /**
     * Permission check: super_admin implicitly holds every permission;
     * everyone else is evaluated against direct grants plus grants attached
     * to their primary role row.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->directPermissions()->where('permissions.name', $permission)->exists()) {
            return true;
        }

        $role = Role::where('name', $this->role)->first();

        return $role
            ? $role->permissions()->where('permissions.name', $permission)->exists()
            : false;
    }

    public function fraudEvents(): HasMany
    {
        return $this->hasMany(FraudEvent::class);
    }

    /**
     * Round 2: linked social identities (google/apple), keyed by the
     * provider's stable subject claim.
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * Round 2: serialized convenience boolean for the verification gate.
     */
    public function getEmailVerifiedAttribute(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function isContributor(): bool
    {
        return $this->role === 'contributor';
    }

    public function isBusiness(): bool
    {
        return $this->role === 'business';
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'superadmin'], true);
    }

    public function isModerator(): bool
    {
        return $this->role === 'moderator';
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'superadmin';
    }
}
