<?php

declare(strict_types=1);

namespace Magna\MessageLite\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Magna\Users\User;

/**
 * One person in one thread, and how much of it they have read.
 *
 * `role` is free text on purpose: "client", "provider", "observer" are the
 * host''s words for its own domain, and constraining them here would mean this
 * plugin having an opinion about somebody else''s roles.
 *
 * @property string $id
 * @property string $conversation_id
 * @property string $user_id
 * @property string|null $role
 * @property Carbon|null $last_read_at
 * @property Carbon|null $muted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
#[Fillable(['role'])]
class Participant extends Model
{
    use HasUlids;

    protected $table = 'message_lite_participants';

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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isMuted(): bool
    {
        return $this->muted_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
            'muted_at' => 'datetime',
        ];
    }
}
