<?php

declare(strict_types=1);

namespace Kami\Cocktail\Policies;

use Kami\Cocktail\Models\Note;
use Kami\Cocktail\Models\User;
use Kami\Cocktail\Models\Cocktail;
use Illuminate\Auth\Access\HandlesAuthorization;

class NotePolicy
{
    use HandlesAuthorization;

    public function show(User $user, Note $note): bool
    {
        $noteable = $note->noteable;

        return $noteable instanceof Cocktail
            && $noteable->bar_id === bar()->id
            && $user->hasBarMembership($noteable->bar_id);
    }

    public function delete(User $user, Note $note): bool
    {
        $noteable = $note->noteable;

        return $user->id === $note->user_id
            && $noteable instanceof Cocktail
            && $noteable->bar_id === bar()->id
            && $user->hasBarMembership($noteable->bar_id);
    }
}
