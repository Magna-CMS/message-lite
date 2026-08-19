<?php

declare(strict_types=1);

namespace Magna\MessageLite\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Magna\MessageLite\Contracts\DecidesParticipation;
use Magna\MessageLite\Models\Conversation;
use Magna\MessageLite\Models\Participant;
use Magna\MessageLite\Support\ModelId;
use Magna\Users\User;

/**
 * Opening threads, and keeping track of who is in them.
 *
 * A conversation is opened by a *host*, in response to something happening —
 * somebody answering a requirement, an order being placed. It is never opened
 * by a person browsing to a screen and asking for one. That is what keeps "who
 * may talk to whom" a decision the application made rather than one the client
 * requested, and it is why there is no "create conversation" endpoint in this
 * plugin at all.
 */
final class ConversationService
{
    public function __construct(private readonly DecidesParticipation $rules) {}

    /**
     * Find or open the thread about one thing, between these people.
     *
     * Idempotent on the context: a second offer on the same requirement
     * continues the same conversation rather than starting a parallel one
     * nobody reads. A thread with no context is always new, because there is
     * nothing to match it on.
     *
     * @param  array<string, string|null>  $participants  user id => role
     */
    public function open(?Model $context, array $participants, ?string $subject = null): Conversation
    {
        return DB::transaction(function () use ($context, $participants, $subject): Conversation {
            $conversation = $context === null
                ? null
                : $this->existingFor($context, array_keys($participants));

            if ($conversation === null) {
                $conversation = new Conversation;
                $conversation->context_type = $context?->getMorphClass();
                $conversation->context_id = $context === null ? null : ModelId::require($context);
                $conversation->subject = $subject;
                $conversation->status = Conversation::STATUS_OPEN;
                $conversation->save();
            }

            foreach ($participants as $userId => $role) {
                $this->addParticipant($conversation, $userId, $role);
            }

            return $conversation->fresh(['participants']) ?? $conversation;
        });
    }

    /**
     * Add somebody to a thread, or leave them where they are.
     *
     * Never re-inserted: the unique index would refuse it, and an existing
     * participant's read state is not something joining again should reset.
     */
    public function addParticipant(Conversation $conversation, string $userId, ?string $role = null): Participant
    {
        $participant = Participant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->first();

        if ($participant instanceof Participant) {
            return $participant;
        }

        $participant = new Participant;
        $participant->conversation_id = $conversation->id;
        $participant->user_id = $userId;
        $participant->role = $role;
        $participant->save();

        return $participant;
    }

    /**
     * The threads one person is in, newest activity first.
     *
     * @return Builder<Conversation>
     */
    public function visibleTo(User $user): Builder
    {
        return Conversation::query()
            ->whereHas('participants', fn (Builder $query) => $query->where('user_id', $user->getKey()))
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at');
    }

    /**
     * Every thread about one thing, whoever is in them. For the host.
     *
     * @return Builder<Conversation>
     */
    public function forContext(Model $context): Builder
    {
        return Conversation::query()
            ->where('context_type', $context->getMorphClass())
            ->where('context_id', ModelId::require($context));
    }

    public function mayRead(User $user, Conversation $conversation): bool
    {
        return $this->rules->mayRead($user, $conversation->loadMissing('participants'));
    }

    public function mayWrite(User $user, Conversation $conversation): bool
    {
        return $this->rules->mayWrite($user, $conversation->loadMissing('participants'));
    }

    /**
     * How many messages this person has not read, across every thread.
     *
     * Counted in one query rather than per thread: this is a badge the header
     * asks for on a timer, so it has to cost one round trip whatever somebody's
     * correspondence looks like.
     *
     * Own messages never count. Nobody has unread mail from themselves.
     */
    public function unreadCount(User $user): int
    {
        return (int) DB::table('message_lite_participants as p')
            ->join('message_lite_messages as m', 'm.conversation_id', '=', 'p.conversation_id')
            ->where('p.user_id', $user->getKey())
            ->whereNull('m.deleted_at')
            ->where('m.sender_user_id', '!=', $user->getKey())
            ->where(function ($query): void {
                $query->whereNull('p.last_read_at')
                    ->orWhereColumn('m.sent_at', '>', 'p.last_read_at');
            })
            ->count();
    }

    /**
     * The same count, broken down by thread.
     *
     * For the list screen, where a single total says somebody wrote but not
     * which correspondence to open. Still one query for the whole page: a
     * per-row count is how a thread list turns into twenty-six round trips.
     *
     * Threads with nothing unread are simply absent from the result rather
     * than present as zero — the caller is reading it as a lookup.
     *
     * @param  list<string>  $conversationIds
     * @return array<string, int>
     */
    public function unreadPerThread(User $user, array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $rows = DB::table('message_lite_participants as p')
            ->join('message_lite_messages as m', 'm.conversation_id', '=', 'p.conversation_id')
            ->where('p.user_id', $user->getKey())
            ->whereIn('p.conversation_id', $conversationIds)
            ->whereNull('m.deleted_at')
            ->where('m.sender_user_id', '!=', $user->getKey())
            ->where(function ($query): void {
                $query->whereNull('p.last_read_at')
                    ->orWhereColumn('m.sent_at', '>', 'p.last_read_at');
            })
            ->groupBy('p.conversation_id')
            ->selectRaw('p.conversation_id as conversation_id, COUNT(*) as total')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            // Aggregate columns come back as whatever the driver felt like —
            // int on one, numeric string on another — so they are narrowed here
            // rather than cast blind.
            $id = $row->conversation_id;
            $total = $row->total;

            if (is_string($id) && is_numeric($total)) {
                $counts[$id] = (int) $total;
            }
        }

        return $counts;
    }

    public function markRead(Conversation $conversation, User $user): void
    {
        Participant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->getKey())
            ->update(['last_read_at' => now(), 'updated_at' => now()]);
    }

    /** Silence a thread without leaving it. */
    public function setMuted(Conversation $conversation, User $user, bool $muted): void
    {
        Participant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->getKey())
            ->update(['muted_at' => $muted ? now() : null, 'updated_at' => now()]);
    }

    public function close(Conversation $conversation): Conversation
    {
        $conversation->status = Conversation::STATUS_CLOSED;
        $conversation->save();

        return $conversation;
    }

    /**
     * The thread about this context that already has exactly these people.
     *
     * Matched on the set, not on any one of them: two providers answering the
     * same requirement are two conversations, and folding them together would
     * put a client's reply to one in front of the other.
     *
     * @param  list<string>  $userIds
     */
    private function existingFor(Model $context, array $userIds): ?Conversation
    {
        $candidates = Conversation::query()
            ->where('context_type', $context->getMorphClass())
            ->where('context_id', ModelId::require($context))
            ->with('participants')
            ->get();

        sort($userIds);

        foreach ($candidates as $candidate) {
            $existing = $candidate->participants->pluck('user_id')->all();
            sort($existing);

            if ($existing === $userIds) {
                return $candidate;
            }
        }

        return null;
    }
}
