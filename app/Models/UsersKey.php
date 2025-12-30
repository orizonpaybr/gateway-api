<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsersKey extends Model
{
    use HasFactory;
    
    protected $table = "users_key";

    protected $fillable = [
        "user_id",
        "token",
        "secret",
        "status",
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'username');
    }
}