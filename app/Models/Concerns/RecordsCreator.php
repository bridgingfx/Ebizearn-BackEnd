<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stamps created_by with the signed-in account when a row is created, so
 * staff and business owners can see who did it (e.g. which business team
 * member created a campaign or a deposit). Existing rows stay null.
 */
trait RecordsCreator
{
    public static function bootRecordsCreator(): void
    {
        static::creating(function ($model) {
            if (empty($model->created_by) && ($id = auth()->id())) {
                $model->created_by = $id;
            }
        });
    }

    /** Who created the row — kept visible after a team member is removed. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
