<?php

declare(strict_types=1);

namespace Magna\MessageLite\Media;

use Magna\Contracts\MediaSource;
use Magna\Contracts\MediaSourceItem;
use Magna\MessageLite\Models\Attachment;

/**
 * Files sent inside private conversations, declared to the media library.
 *
 * Attachments are stored on the plugin's own disk with no public URL, because
 * a file shared in a thread is readable by the people in that thread and
 * nobody else. The library therefore counts them and names them; it does not
 * open them, and it offers no thumbnail even for images — a preview grid of
 * everything anyone has ever sent each other is a surveillance tool, whatever
 * the intent behind building it.
 *
 * The count alone is what the operator needed: storage that was growing with
 * no screen anywhere admitting the files existed.
 */
final class AttachmentsMediaSource implements MediaSource
{
    public function key(): string
    {
        return 'message-lite.attachments';
    }

    public function label(): string
    {
        return 'Message attachments';
    }

    /** The oversight permission, the same one thread moderation requires. */
    public function permission(): ?string
    {
        return 'message-lite.threads.oversee';
    }

    public function isConfidential(): bool
    {
        return true;
    }

    public function count(): int
    {
        return Attachment::query()->count();
    }

    /**
     * @return list<MediaSourceItem>
     */
    public function items(int $page, int $perPage, string $search = ''): array
    {
        return Attachment::query()
            ->when($search !== '', fn ($query) => $query->where('original_name', 'like', '%'.$search.'%'))
            ->latest()
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (Attachment $attachment): MediaSourceItem => new MediaSourceItem(
                id: (string) $attachment->getKey(),
                name: $attachment->original_name,
                mimeType: $attachment->mime_type,
                sizeBytes: $attachment->size_bytes,
                uploadedAt: $attachment->created_at,
                // Not the sender and not the thread subject: who is talking to
                // whom is exactly what an oversight screen should not spell out
                // before somebody opens the thread with a reason to.
                ownerLabel: 'Private conversation',
                manageUrl: null,
                thumbnailUrl: null,
            ))
            ->all();
    }
}
