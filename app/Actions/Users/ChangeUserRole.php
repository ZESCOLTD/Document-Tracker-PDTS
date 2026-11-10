<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Gives a user exactly one role, or none, through Spatie so it controls
 * what they can do. Every Users screen goes through here.
 */
class ChangeUserRole
{
    public const PROTECTED_PERMISSION = 'users.manage';

    /**
     * @throws ValidationException when the role does not exist, or when the
     *                             actor would remove their own access to user management
     */
    public function __invoke(User $user, ?string $roleName, ?User $actor = null, string $field = 'role'): void
    {
        $roleName = $roleName === '' ? null : $roleName;
        $role = $roleName ? Role::findByName($roleName, 'web') : null;

        if ($actor && $actor->is($user) && $actor->can(self::PROTECTED_PERMISSION)
            && ! $role?->hasPermissionTo(self::PROTECTED_PERMISSION)) {
            throw ValidationException::withMessages([
                $field => 'You cannot remove your own access to user management. Ask another administrator to change your role.',
            ]);
        }

        $user->syncRoles($role ? [$role] : []);
    }

    /**
     * Validation rule for a role name chosen on a form. Empty means no role.
     */
    public static function rule(): array
    {
        return ['nullable', 'string', 'exists:roles,name'];
    }
}
