<?php

declare(strict_types=1);

namespace Magna\MessageLite\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One file attached to a message.
 *
 * Not `#[Fillable]` by design: every column here is decided by the server from
 * the uploaded file itself — the stored path, the sniffed type, the measured
 * size. There is nothing on this model a client should be able to set.
 *
 * @property string $id
 * @property string $message_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $checksum
 * @property Carbon|null $created_at
 * @property-read Message|null $message
 */
class Attachment extends Model
{
    use HasUlids;

    protected $table = 'message_lite_attachments';

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    /** Whether this is something a browser can show inline rather than save. */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }
}
