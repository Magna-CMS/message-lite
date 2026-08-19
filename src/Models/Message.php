<?php

declare(strict_types=1);

namespace Magna\MessageLite\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Magna\Users\User;

/**
 * One thing somebody said.
 *
 * Soft-deleted, because "delete for everyone" has to leave the thread readable
 * around the gap — a hard delete would renumber somebody else''s replies out of
 * their context.
 *
 * @property string $id
 * @property string $conversation_id
 * @property string $sender_user_id
 * @property string|null $reply_to_id
 * @property string $body
 * @property Carbon $sent_at
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $sender
 * @property-read Message|null $replyTo
 */
#[Fillable(['body'])]
class Message extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'message_lite_messages';

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    /**
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'message_id');
    }

    public function wasEdited(): bool
    {
        return $this->edited_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }
}
