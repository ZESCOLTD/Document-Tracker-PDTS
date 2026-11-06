<?php

namespace App\Livewire\Users;

use App\Models\User;
use App\Models\Role;
use App\Models\IpaPhrisView;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Jobs\SyncAllUsersFromPhrisJob;
use App\Jobs\SyncSingleUserFromPhrisJob;
class UserIndex extends Component
{
    use WithPagination, WithFileUploads;
// 🔒 HIDDEN PHRIS DATA (To save in background)
    public $phrisData = [];
    protected $paginationTheme = 'bootstrap';

    public $search = '';
    public $roleFilter = '';
    public $statusFilter = '';

    // 🔥 MODAL FIELDS
    public $modal_man_no;
    public $modal_name;
    public $modal_email;
    public $modal_directorate;
    public $modal_job_title;

    // 🖼 Avatar upload
    public $avatar;

    public $syncRunning = false;
    public $syncedUsers = 0;
    public $totalSyncUsers = 0;
    public $syncPercentage = 0;
    protected $updatesQueryString = ['search', 'roleFilter', 'statusFilter'];

    protected $listeners = ['deleteUserConfirmed'];

    /*
    |--------------------------------------------------------------------------
    | RESET PAGINATION
    |--------------------------------------------------------------------------
    */
    public function updatingSearch() { $this->resetPage(); }
    public function updatingStatusFilter() { $this->resetPage(); }
    public function updatingRoleFilter() { $this->resetPage(); }

    public function hydrate()
    {
        session()->forget('success');
        session()->forget('error');
    }

    /*
    |--------------------------------------------------------------------------
    | TOGGLE STATUS
    |--------------------------------------------------------------------------
    */
    public function toggleStatus($id)
    {
        $user = User::findOrFail($id);
        $user->is_banned = !$user->is_banned;
        $user->save();
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE USER
    |--------------------------------------------------------------------------
    */
    public function deleteUserConfirmed($data)
    {
        User::findOrFail($data['id'])->delete();
        session()->flash('success', 'User deleted successfully.');
    }

    /*
    |--------------------------------------------------------------------------
    | SYNC PHCMS
    |--------------------------------------------------------------------------
    */
    public function syncPhcms()
    {
        sleep(1);
        session()->flash('success', 'PHCMS sync completed successfully.');
    }

    /*
    |--------------------------------------------------------------------------
    | FETCH USER FROM PHCMS
    |--------------------------------------------------------------------------
    */
    public function fetchUserFromPhcms()
    {
        if (!$this->modal_man_no) {
            session()->flash('error', 'Please enter a Man Number.');
            return;
        }

        $data = IpaPhrisView::where('CON_PER_NO', $this->modal_man_no)->first();

        if (!$data) {
            session()->flash('error', 'User not found in PHCMS.');
            return;
        }
// ✅ ADD THIS LINE: This fills the array you are trying to dd() later
        $this->phrisData = $data->toArray();

        $this->modal_name = $data->name;
        $this->modal_email = $data->staff_email;
        $this->modal_directorate = $data->directorate;
        $this->modal_job_title = $data->job_title;
    }

    /*
    |--------------------------------------------------------------------------
    | SAVE USER
    |--------------------------------------------------------------------------
    */
    public function saveFromModal()
    {
//        dd($this->phrisData);
        if (!$this->modal_man_no || !$this->modal_email) {
            session()->flash('error', 'Missing required user details.');
            return;
        }

        if (User::where('man_no', $this->modal_man_no)->exists()) {
            session()->flash('error', 'User already exists.');
            return;
        }

        $avatarPath = null;

        if ($this->avatar) {
            $avatarPath = $this->avatar->store('avatars', 'public');
        }

        User::create([
            /*
            |--------------------------------------------------------------------------
            | BASIC DETAILS
            |--------------------------------------------------------------------------
            */
            'man_no' => $this->phrisData['con_per_no'] ?? null,
            'nrc' => $this->phrisData['nrc'] ?? null,
            'username' => $this->phrisData['username'] ?? null,
            'name' => $this->phrisData['name'] ?? null,
            'dob' => $this->phrisData['dob'] ?? null,
            'sex' => $this->phrisData['sex'] ?? null,
            'mobile_no' => $this->phrisData['mobile_no'] ?? null,
            'email' => $this->phrisData['staff_email'] ?? null,

            /*
            |--------------------------------------------------------------------------
            | BUSINESS UNIT
            |--------------------------------------------------------------------------
            */
            'bu_code' => $this->phrisData['bu_code'] ?? null,
            'bu_name' => $this->phrisData['bu_name'] ?? null,
            'cc_code' => $this->phrisData['cc_code'] ?? null,
            'cc_name' => $this->phrisData['cc_name'] ?? null,

            /*
            |--------------------------------------------------------------------------
            | ORGANIZATION
            |--------------------------------------------------------------------------
            */
            'directorate' => $this->phrisData['directorate'] ?? null,
            'division' => $this->phrisData['division'] ?? null,
            'location_code' => $this->phrisData['location_code'] ?? null,
            'location' => $this->phrisData['location'] ?? null,
            'function_section_code' => $this->phrisData['function_section_code'] ?? null,
            'functional_section' => $this->phrisData['functional_section'] ?? null,
            'station' => $this->phrisData['station'] ?? null,
            'position' => $this->phrisData['position'] ?? null,
            'job_code' => $this->phrisData['job_code'] ?? null,
            'job_title' => $this->phrisData['job_title'] ?? null,
            'grade' => $this->phrisData['grade'] ?? null,

            /*
            |--------------------------------------------------------------------------
            | CONTRACT
            |--------------------------------------------------------------------------
            */
            'group_type' => $this->phrisData['group_type'] ?? null,
            'con_st_code' => $this->phrisData['con_st_code'] ?? null,
            'contract_type' => $this->phrisData['contract_type'] ?? null,
            'con_wef_date' => $this->phrisData['con_wef_date'] ?? null,
            'con_wet_date' => $this->phrisData['con_wet_date'] ?? null,

            /*
            |--------------------------------------------------------------------------
            | EXTRA
            |--------------------------------------------------------------------------
            */
            'avatar' => $avatarPath,
            'password' => bcrypt('12345678'),
        ]);

        $this->reset([
            'modal_man_no',
            'modal_name',
            'modal_email',
            'modal_directorate',
            'modal_job_title',
            'avatar'
        ]);

        session()->flash('success', 'User added successfully.');

        $this->dispatch('user-added');
    }

    public function startFullSync()
    {
        $this->syncRunning = true;

        Cache::put('phris_sync_total', User::count());
        Cache::put('phris_sync_count', 0);
        Cache::put('phris_sync_running', true);

        SyncAllUsersFromPhrisJob::dispatch();

        session()->flash('success', 'Background sync started.');
    }

    public function refreshSyncProgress()
    {
        $this->syncRunning = Cache::get('phris_sync_running', false);

        $this->syncedUsers = Cache::get('phris_sync_count', 0);

        $this->totalSyncUsers = Cache::get('phris_sync_total', 0);

        $this->syncPercentage = $this->totalSyncUsers > 0
            ? round(($this->syncedUsers / $this->totalSyncUsers) * 100)
            : 0;
    }

    public function syncSingleUser($id)
    {
        SyncSingleUserFromPhrisJob::dispatch($id);

        session()->flash('success', 'User sync queued successfully.');
    }

    /*
    |--------------------------------------------------------------------------
    | RENDER (🔥 UPDATED WITH SESSIONS)
    |--------------------------------------------------------------------------
    */
    public function render()
    {
        $query = User::query()
            ->select('users.*')
            ->selectSub(function ($q) {
                $q->from('sessions')
                    ->selectRaw('MAX(last_activity)')
                    ->whereColumn('sessions.user_id', 'users.id');
            }, 'last_activity');


        /*
        |--------------------------------------------------------------------------
        | SEARCH
        |--------------------------------------------------------------------------
        */
        if (!empty($this->search)) {
            $search = trim($this->search);

            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%")
                    ->orWhere('users.man_no', 'like', "%{$search}%")
                    ->orWhere('users.job_title', 'like', "%{$search}%")
                    ->orWhere('users.directorate', 'like', "%{$search}%");
            });
        }

        /*
        |--------------------------------------------------------------------------
        | ROLE FILTER
        |--------------------------------------------------------------------------
        */
        if (!empty($this->roleFilter)) {
            $query->where('users.role_id', $this->roleFilter);
        }

        /*
        |--------------------------------------------------------------------------
        | STATUS FILTER
        |--------------------------------------------------------------------------
        */
        if ($this->statusFilter === 'active') {
            $query->where('users.is_banned', false);
        } elseif ($this->statusFilter === 'inactive') {
            $query->where('users.is_banned', true);
        }

        $users = $query->orderByDesc('users.created_at')->paginate(10);

        /*
        |--------------------------------------------------------------------------
        | STATS
        |--------------------------------------------------------------------------
        */
        $totalUsers = User::count();
        $activeUsers = User::where('is_banned', false)->count();
        $bannedUsers = User::where('is_banned', true)->count();
        $newUsersThisMonth = User::whereMonth('created_at', now()->month)->count();

        return view('livewire.users.index', [
            'users' => $users,
            'roles' => Role::all(),

            'totalUsers' => $totalUsers,
            'activeUsers' => $activeUsers,
            'bannedUsers' => $bannedUsers,
            'adminUsers' => 0,
            'newUsersThisMonth' => $newUsersThisMonth,
            'activePercentage' => $totalUsers > 0
                ? round(($activeUsers / $totalUsers) * 100)
                : 0,
        ])->layout('layouts.master');
    }
}
