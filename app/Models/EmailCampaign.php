<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailCampaign extends Model
{
    public const AUDIENCES = ['all', 'contributors', 'businesses'];

    protected $fillable = [
        'name', 'subject', 'heading', 'body', 'button_label', 'button_url', 'audience', 'template_key', 'status',
        'total_recipients', 'sent_count', 'failed_count', 'last_user_id', 'last_error',
        'created_by', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'total_recipients' => 'integer',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
        'last_user_id' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
