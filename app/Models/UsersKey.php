<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsersKey extends Model
{
    protected $table = "users_key";

    protected $fillable = [
        "user_id",
        "token",
        "secret",
        "status",
        "user_id",
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}