<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A photo or video attached to a campaign. Contributors see (and can
 * download) it on the task page.
 */
class CampaignMedia extends Model
{
    protected $table = 'campaign_media';

    protected $fillable = ['campaign_id', 'type', 'path', 'mime_type', 'size_bytes', 'original_name', 'sort_order', 'uploaded_by'];

    protected $casts = ['size_bytes' => 'integer', 'sort_order' => 'integer'];

    protected $appends = ['url'];

    protected $hidden = ['path'];

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->attributes['path']);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
