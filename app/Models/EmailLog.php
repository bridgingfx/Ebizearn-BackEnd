<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    protected $fillable = ['event_key', 'email_provider_id', 'provider_name', 'to_email', 'subject', 'status', 'error'];
}
