<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

final class User extends Authenticatable
{
    use HasUuids;
    use Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'email',
        'normalized_email',
        'display_name',
        'password',
        'status',
        'password_change_required',
        'temporary_password_issued_at',
        'temporary_password_expires_at',
        'session_version',
        'last_login_at',
        'password_changed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password_change_required' => 'boolean',
            'temporary_password_issued_at' => 'immutable_datetime',
            'temporary_password_expires_at' => 'immutable_datetime',
            'session_version' => 'integer',
            'last_login_at' => 'immutable_datetime',
            'password_changed_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }
}
