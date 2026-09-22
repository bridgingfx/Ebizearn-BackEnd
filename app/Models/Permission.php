<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    /**
     * Canonical permission names assignable by Super Admin.
     */
    public const REVIEW_SUBMISSIONS = 'review_submissions';
    public const MANAGE_CAMPAIGNS = 'manage_campaigns';
    public const MANAGE_USERS = 'manage_users';
    public const MANAGE_SETTINGS = 'manage_settings';
    public const HANDLE_DISPUTES = 'handle_disputes';
    public const MANAGE_TASK_TEMPLATES = 'manage_task_templates';
    public const VIEW_REPORTS = 'view_reports';

    public static function catalog(): array
    {
        return [
            self::REVIEW_SUBMISSIONS => 'Review task submissions (approve / reject)',
            self::MANAGE_CAMPAIGNS => 'Manage campaigns (create / edit / status)',
            self::MANAGE_USERS => 'Manage users (status, roles)',
            self::MANAGE_SETTINGS => 'Manage platform settings',
            self::HANDLE_DISPUTES => 'Handle disputes and fraud cases',
            self::MANAGE_TASK_TEMPLATES => 'Manage task templates',
            self::VIEW_REPORTS => 'View reports and analytics',
        ];
    }

    protected $fillable = [
        'name',
        'label',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role')->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'permission_user')->withTimestamps();
    }
}
