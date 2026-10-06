<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Task Library recipe. Content only — a template never publishes anything;
 * it pre-fills the campaign wizard (business) or the post-campaign form
 * (staff). Super Admin controls which audiences see each one.
 */
class TaskTemplate extends Model
{
    /** Icon keys the frontend knows how to render. */
    public const ICONS = ['share', 'video', 'comment', 'app', 'whatsapp', 'at', 'tag', 'survey', 'megaphone', 'star'];

    protected $fillable = [
        'name',
        'icon',
        'description',
        'duration_label',
        'reward_label',
        'template_key',
        'task_type_key',
        'platform',
        'instructions',
        'visible_to_business',
        'visible_to_admin',
        'is_active',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'visible_to_business' => 'boolean',
        'visible_to_admin' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
