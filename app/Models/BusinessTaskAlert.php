<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The bell on a business profile: this user gets an in-app notification
 * whenever the business publishes a task.
 */
class BusinessTaskAlert extends Model
{
    protected $fillable = ['user_id', 'business_id'];
}
