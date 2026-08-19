<?php

declare(strict_types=1);

namespace Magna\MessageLite\Contracts;

use Magna\MessageLite\Models\Conversation;
use Magna\Users\User;

/**
 * The host's answer to "may these two people talk, and may this one read that".
 *
 * Message Lite's own rule is participation: you are in the thread or you are
 * not. That is deliberately the whole of it — a messaging plugin that knew
 * about empanelment, or orders, or tickets, would be a messaging plugin for
 * exactly one application.
 *
 * A host with richer rules binds an implementation of this in its own service
 * provider. An administrator who may oversee correspondence, a supervisor who
 * may read but not write, a thread that closes when the work does — all of that
 * is the host's business and none of it belongs here.
 *
 * Nothing is bound by default. `ParticipationOnly` is used when the host has
 * not said otherwise, which is the safe answer rather than the permissive one.
 */
interface DecidesParticipation
{
    /**
     * May this person read the thread at all?
     *
     * Called before the messages are fetched, so returning false is a refusal
     * rather than an empty list — the difference between "nothing was said" and
     * "this is not yours to read".
     */
    public function mayRead(User $user, Conversation $conversation): bool;

    /**
     * May this person add to it?
     *
     * Separate from reading because they genuinely come apart: an overseer
     * reads without writing, and a thread whose work is finished is readable by
     * everybody who was in it and writable by nobody.
     */
    public function mayWrite(User $user, Conversation $conversation): bool;
}
