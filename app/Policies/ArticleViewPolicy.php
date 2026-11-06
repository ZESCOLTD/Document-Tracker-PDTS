<?php

namespace App\Policies;

use App\Models\ArticleView;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;


class ArticleViewPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {

    }

    public function view(User $user, ArticleView $articleView): bool
    {
    }

    public function create(User $user): bool
    {
    }

    public function update(User $user, ArticleView $articleView): bool
    {
    }

    public function delete(User $user, ArticleView $articleView): bool
    {
    }

    public function restore(User $user, ArticleView $articleView): bool
    {
    }

    public function forceDelete(User $user, ArticleView $articleView): bool
    {
    }
}
