<?php

namespace App\Modules\Tasks\Policies;

use App\Models\User;
use App\Modules\Tasks\Models\Task;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('tasks.view');
    }

    public function view(User $user, Task $task): bool
    {
        return $user->hasPermission('tasks.view') && $this->dansPerimetre($user, $task);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('tasks.create');
    }

    public function update(User $user, Task $task): bool
    {
        return $user->hasPermission('tasks.update') && $this->dansPerimetre($user, $task);
    }

    public function assign(User $user, Task $task): bool
    {
        return $user->hasPermission('tasks.assign') && $this->dansPerimetre($user, $task);
    }

    public function complete(User $user, Task $task): bool
    {
        return $user->hasPermission('tasks.complete') && $this->dansPerimetre($user, $task);
    }

    public function cancel(User $user, Task $task): bool
    {
        return $user->hasPermission('tasks.cancel') && $this->dansPerimetre($user, $task);
    }

    private function dansPerimetre(User $user, Task $task): bool
    {
        return $task->faculte_id === null || $user->peutAccederFaculte((int) $task->faculte_id);
    }
}
