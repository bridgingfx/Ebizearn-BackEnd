<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A "request a demo" submission from the public site. Created by anonymous
 * visitors (rate-limited); read and triaged by admin/superadmin staff via
 * GET /api/v1/admin/demo-requests.
 */
class DemoRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'company',
        'message',
        'status',
    ];
}
