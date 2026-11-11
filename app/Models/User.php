<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'man_no',
        'nrc',
        'username',
        'name',
        'dob',
        'sex',
        'mobile_no',
        'email',
        'password',
        'bu_code',
        'bu_name',
        'cc_code',
        'cc_name',
        'directorate',
        'division',
        'location_code',
        'location',
        'function_section_code',
        'functional_section',
        'station',
        'position',
        'job_code',
        'job_title',
        'grade',
        'group_type',
        'con_st_code',
        'contract_type',
        'con_wef_date',
        'con_wet_date',
        'is_banned',
        'banned_until',
        'notify_email',
        'notify_in_app',
        'notify_categories',
    ];

    /**
     * The attributes that should be hidden for arrays.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'dob' => 'date',
        'con_wef_date' => 'date',
        'con_wet_date' => 'date',
        'banned_until' => 'datetime',
        'is_banned' => 'boolean',
        'notify_email' => 'boolean',
        'notify_in_app' => 'boolean',
        'notify_categories' => 'array',
    ];

    /**
     * Should this user receive notifications for the given category over the given channel?
     * Empty or unknown categories are allowed by default.
     */
    public function wantsNotification(string $channel, ?string $category = null): bool
    {
        if ($channel === 'mail' && ! ($this->notify_email ?? true)) {
            return false;
        }
        if ($channel === 'database' && ! ($this->notify_in_app ?? true)) {
            return false;
        }

        if ($category) {
            $cats = $this->notify_categories ?? [];
            if (array_key_exists($category, $cats) && $cats[$category] === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    public function getFullnameAttribute()
    {
        return $this->name;
    }

    public function pdtsOfficeAssignments()
    {
        return $this->hasMany(PdtsEmployeeOfficeAssignment::class);
    }

    public function currentPdtsOfficeAssignments()
    {
        return $this->pdtsOfficeAssignments()
            ->whereNull('ends_at')
            ->with('office.department');
    }

    /**
     * The office shown when tracking documents: the current primary office
     * assignment, falling back to the HR section or directorate.
     */
    public function officeName(): ?string
    {
        $assignment = $this->currentPdtsOfficeAssignments()
            ->orderByDesc('is_primary')
            ->latest('starts_at')
            ->first();

        return $assignment?->office?->name ?: $this->functional_section ?: $this->directorate ?: null;
    }

    /**
     * Position and office, for example "Finance Manager, Accounts". Empty when unknown.
     */
    public function positionAndOffice(): string
    {
        return implode(', ', array_unique(array_filter([$this->job_title, $this->officeName()])));
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function getLastActivityAttribute()
    {
        return DB::table('sessions')
            ->where('user_id', $this->id)
            ->orderByDesc('last_activity')
            ->value('last_activity');
    }

    public function isOnline()
    {
        $lastActivity = $this->last_activity;

        return $lastActivity
            ? Carbon::createFromTimestamp($lastActivity)->gt(now()->subMinutes(5))
            : false;
    }

    public function lastSeen()
    {
        $lastActivity = $this->last_activity;

        return $lastActivity
            ? Carbon::createFromTimestamp($lastActivity)
            : null;
    }
}
