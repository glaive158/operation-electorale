<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name','prenom','email','password',
        'role','actif','telephone',
        'region_id','region_nom',
        'departement_id','departement_nom',
        'arrondissement_id','arrondissement_nom',
        'commune_id','commune_nom',
    ];

    protected $hidden = ['password','remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'actif'             => 'boolean',
        ];
    }

    /* ── Role helpers ── */

    public function isAdmin(): bool         { return $this->role === 'admin'; }
    public function isGouverneur(): bool    { return $this->role === 'gouverneur'; }
    public function isPrefet(): bool        { return $this->role === 'prefet'; }
    public function isSousPrefet(): bool    { return $this->role === 'sous_prefet'; }
    public function isCommission(): bool    { return $this->role === 'commission'; }

    public function hasRole(string|array $roles): bool
    {
        return in_array($this->role, (array) $roles);
    }

    public function canValidate(): bool
    {
        return $this->hasRole(['admin','prefet','sous_prefet','gouverneur']);
    }

    public function getNomCompletAttribute(): string
    {
        return trim($this->prenom . ' ' . $this->name);
    }

    /* ── Zone label ── */

    public function getZoneLabelAttribute(): string
    {
        return match($this->role) {
            'gouverneur'  => $this->region_nom ?? '—',
            'prefet'      => $this->departement_nom ?? '—',
            'sous_prefet' => $this->arrondissement_nom ?? '—',
            'commission'  => $this->commune_nom ?? '—',
            default       => 'Toutes zones',
        };
    }

    /* ── Notifications ── */

    public function notifications()
    {
        return $this->hasMany(NotificationOp::class, 'user_id')->latest();
    }

    public function unreadNotificationsCount(): int
    {
        return $this->notifications()->where('lue', false)->count();
    }

    /* ── Operations ── */

    public function operations()
    {
        return $this->hasMany(Operation::class, 'user_id');
    }
}
