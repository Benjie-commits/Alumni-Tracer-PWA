<?php

namespace App\Models;

use App\Enums\RoleSlug;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password', 'role_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function alumniProfile(): HasOne
    {
        return $this->hasOne(AlumniProfile::class);
    }

    public function hasRole(RoleSlug ...$slugs): bool
    {
        $current = $this->role?->slug;

        foreach ($slugs as $slug) {
            if ($current === $slug->value) {
                return true;
            }
        }

        return false;
    }

    public function isStaff(): bool
    {
        return $this->hasRole(...RoleSlug::staff());
    }

    public function isAlumnus(): bool
    {
        return $this->hasRole(RoleSlug::Alumni);
    }

    /** Registrar and ICT admin may change records; QA/Dean viewers are read-only. */
    public function canManageRecords(): bool
    {
        return $this->hasRole(RoleSlug::Registrar, RoleSlug::IctAdmin);
    }
}
