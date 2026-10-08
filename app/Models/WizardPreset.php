<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What the business campaign wizard pre-fills for a Task Library template. */
class WizardPreset extends Model
{
    protected $fillable = ['key', 'label', 'task_type_key', 'category_id', 'platform', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(TaskCategory::class);
    }
}
