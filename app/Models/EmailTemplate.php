<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = ['event_key', 'name', 'subject', 'html_body', 'text_body', 'variables', 'is_enabled', 'is_custom'];

    // NOTE: Laravel 10.50 does not support the model `casts()` method form
    // (Laravel 11+ only), so casts are declared as a property.
    protected $casts = [
        'variables' => 'array',
        'is_enabled' => 'boolean',
        'is_custom' => 'boolean',
    ];
}
