<?php

declare(strict_types=1);

namespace Magna\MessageLite\Support;

use Magna\MessageLite\Contracts\DecidesParticipation;
use Magna\MessageLite\Models\Conversation;
use Magna\Users\User;

/**
 * The default rule: you are in the thread, or you are not.
 *
 * Used when a host has not bound its own. Deliberately the strict answer rather
 * than the convenient one — a plugin that shipped a permissive default would
 * make "we forgot to configure it" and "we meant everyone to read this" look
 * identical from the outside.
 *
 * A closed thread is still readable by its participants. Ending a conversation
 * is not the same as withdrawing what was said in it, and a host that wants the
 * stricter reading can say so in its own implementation.
 */
final class ParticipationOnly implements DecidesParticipation
{
    public function mayRead(User $user, Conversation $conversation): bool
    {
        return $conversation->hasParticipant($user);
    }

    public function mayWrite(User $user, Conversation $conversation): bool
    {
        return $conversation->isOpen() && $conversation->hasParticipant($user);
    }
}
