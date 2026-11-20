<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    protected static function booted(): void
    {
        static::addGlobalScope('qrpass-not-archived', function ($builder) {
            $builder->whereNull($builder->getModel()->getTable() . '.qrpass_archived_at');
        });
    }

    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'username',
        'role',
        'status',
        'password',
        'profile_photo',
        'college',
        'year_level',
        'contact_number',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function registeredItems()
    {
        return $this->hasMany(RegisteredItem::class);
    }
}