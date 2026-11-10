<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Role;
use App\Models\Package;
use App\Models\ImportantDate;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'last_name',
        'email',
        'password',
        'phone',
        'state',
        'is_frequent_client',
        'role_id',
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
            'is_frequent_client' => 'boolean',
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function packages()
    {
        return $this->hasMany(Package::class);
    }

    public function hasRole(string $role): bool
    {
        if (!$this->role) {
            return false;
        }

        return Role::normalize($this->role->name) === Role::normalize($role);
    }
    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function importantDates()
    {
        return $this->hasMany(ImportantDate::class);
    }
}
