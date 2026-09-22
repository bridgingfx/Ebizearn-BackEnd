<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = ['event_key', 'name', 'subject', 'html_body', 'text_body', 'variables', 'is_enabled'];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_enabled' => 'boolean',
        ];
    }
}
