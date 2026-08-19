<?php

declare(strict_types=1);

namespace Magna\MessageLite\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Magna\MessageLite\Support\ModelId;
use Magna\Users\User;

/**
 * One thread.
 *
 * `context_type` and `context_id` are the host's, and this plugin never reads
 * them for meaning — only ever to find the threads about one thing. That is
 * what lets the same table serve a service request here and an order somewhere
 * else.
 *
 * @property string $id
 * @property string|null $context_type
 * @property string|null $context_id
 * @property string|null $subject
 * @property string $status
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Participant> $participants
 * @property-read Collection<int, Message> $messages
 */
#[Fillable(['subject', 'status'])]
class Conversation extends Model
{
    use HasUlids;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $table = 'message_lite_conversations';

    /**
     * @return MorphTo<Model, $this>
     */
    public function context(): MorphTo
    {
        return $this->morphTo('context');
    }

    /**
     * @return HasMany<Participant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class, 'conversation_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * Whether somebody is in this thread.
     *
     * Reads through the relation so a caller that eager-loaded participants
     * pays for no query, and one that did not pays for exactly one.
     */
    public function hasParticipant(User $user): bool
    {
        return $this->participants->contains(
            fn (Participant $participant): bool => $participant->user_id === ModelId::require($user),
        );
    }

    public function participantFor(User $user): ?Participant
    {
        return $this->participants->first(
            fn (Participant $participant): bool => $participant->user_id === ModelId::require($user),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }
}
