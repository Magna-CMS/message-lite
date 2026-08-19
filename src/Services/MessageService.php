<?php

declare(strict_types=1);

namespace Magna\MessageLite\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Magna\MessageLite\Models\Conversation;
use Magna\MessageLite\Models\Message;
use Magna\MessageLite\Support\ModelId;
use Magna\Users\User;

/**
 * Posting and reading messages.
 *
 * Reads are cursor-based — everything after a message id — rather than paged.
 * The portal polls, and a poll that asks "what is new" is one indexed query,
 * where "page one again" is the whole thread every few seconds.
 *
 * Nothing here decides who may read or write. That is the host's, through
 * DecidesParticipation, and the controllers ask before they call these.
 */
final class MessageService
{
    /** A thread's opening read, and the cap on any one poll. */
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    public function post(Conversation $conversation, User $sender, string $body, ?string $replyToId = null): Message
    {
        return DB::transaction(function () use ($conversation, $sender, $body, $replyToId): Message {
            $message = new Message;
            $message->conversation_id = $conversation->id;
            $message->sender_user_id = ModelId::require($sender);
            // Only within this thread. A reply pointing at another
            // conversation's message would quote something the readers here
            // cannot see.
            $message->reply_to_id = $this->replyTargetIn($conversation, $replyToId);
            $message->body = $body;
            $message->sent_at = now();
            $message->save();

            // Denormalised so the thread list can sort without touching the
            // messages table — the list is read far more often than it changes.
            $conversation->last_message_at = $message->sent_at;
            $conversation->save();

            return $message;
        });
    }

    /**
     * Everything after one message.
     *
     * @return Collection<int, Message>
     */
    public function since(Conversation $conversation, ?string $afterId, int $limit = self::DEFAULT_LIMIT): Collection
    {
        $query = Message::query()
            ->where('conversation_id', $conversation->id)
            ->with('sender:id,name')
            ->orderBy('sent_at')
            ->orderBy('id')
            ->limit($this->cap($limit));

        if ($afterId !== null) {
            $after = Message::query()->whereKey($afterId)->first();

            if ($after instanceof Message) {
                // Tie-broken on the id: two messages can share a second, and
                // "greater than sent_at" alone would drop one of them for ever.
                $query->where(function ($inner) use ($after): void {
                    $inner->where('sent_at', '>', $after->sent_at)
                        ->orWhere(function ($tie) use ($after): void {
                            $tie->where('sent_at', $after->sent_at)->where('id', '>', $after->id);
                        });
                });
            }
        }

        return $query->get();
    }

    /**
     * The tail of a thread, oldest first once it arrives.
     *
     * @return Collection<int, Message>
     */
    public function latest(Conversation $conversation, int $limit = self::DEFAULT_LIMIT): Collection
    {
        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->with('sender:id,name')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit($this->cap($limit))
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * The last thing said in each of these threads.
     *
     * For a thread list, which is unreadable without it — a column of names and
     * timestamps tells you somebody wrote, not what about. One query for the
     * whole page rather than one per row.
     *
     * `MAX(id)` picks the latest because message ids are ULIDs, which sort in
     * the order they were minted. `MAX(sent_at)` would tie whenever two
     * messages share a second, and a tie here returns two rows for one thread.
     *
     * @param  list<string>  $conversationIds
     * @return array<string, Message>
     */
    public function previewsFor(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $latestIds = DB::table('message_lite_messages')
            ->selectRaw('MAX(id) as id')
            ->whereIn('conversation_id', $conversationIds)
            ->whereNull('deleted_at')
            ->groupBy('conversation_id');

        /** @var array<string, Message> $previews */
        $previews = Message::query()
            ->whereIn('id', $latestIds->pluck('id')->all())
            ->with('sender:id,name')
            ->get()
            ->keyBy('conversation_id')
            ->all();

        return $previews;
    }

    /**
     * Change what was said.
     *
     * Stamped rather than silent: a message that can be rewritten with no trace
     * is a message nobody can rely on having read.
     */
    public function edit(Message $message, string $body): Message
    {
        $message->body = $body;
        $message->edited_at = now();
        $message->save();

        return $message;
    }

    /**
     * Take a message back.
     *
     * Soft-deleted, so the thread reads around the gap rather than closing over
     * it — replies to it keep their place, and their authors keep their words.
     */
    public function withdraw(Message $message): void
    {
        $message->delete();
    }

    /**
     * The message being replied to, if it is in this thread.
     *
     * A target from another conversation is dropped rather than refused: the
     * reply is still worth sending, and quoting something its readers cannot
     * see would be worse than quoting nothing.
     */
    private function replyTargetIn(Conversation $conversation, ?string $replyToId): ?string
    {
        if ($replyToId === null) {
            return null;
        }

        $exists = Message::query()
            ->whereKey($replyToId)
            ->where('conversation_id', $conversation->id)
            ->exists();

        return $exists ? $replyToId : null;
    }

    private function cap(int $limit): int
    {
        return max(1, min(self::MAX_LIMIT, $limit));
    }
}
