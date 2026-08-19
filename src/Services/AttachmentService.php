<?php

declare(strict_types=1);

namespace Magna\MessageLite\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Magna\MessageLite\Exceptions\AttachmentException;
use Magna\MessageLite\Models\Attachment;
use Magna\MessageLite\Models\Message;

/**
 * Files on a message: stored, and later streamed back.
 *
 * Three rules, all of them about not trusting the upload. They are the same
 * three EMBHAS already applies to uploaded evidence, which is the argument for
 * them living here once rather than in every host:
 *
 * 1. **The stored name is generated.** A file called `../../.env` must not get
 *    to decide where anything lands. The original name is kept as a label for
 *    the download header and is never used to build a path.
 * 2. **The type comes from the bytes.** `getClientMimeType()` is whatever the
 *    browser said, and renaming a script to `.pdf` must not get it past the
 *    allowlist.
 * 3. **The disk has no public URL.** Message attachments are somebody's private
 *    correspondence. They are served by a controller that asks the host whether
 *    this reader may see the thread — never by a guessable path.
 *
 * Whether attachments are offered at all is the host's decision; this service
 * is the machinery, not the policy.
 */
final class AttachmentService
{
    /**
     * Store files against a message.
     *
     * All or nothing per call: a partial attach would leave a message claiming
     * three files and carrying one, and the sender has no way to tell which
     * went missing.
     *
     * @param  list<UploadedFile>  $files
     * @return list<Attachment>
     *
     * @throws AttachmentException
     */
    public function attach(Message $message, array $files): array
    {
        if ($files === []) {
            return [];
        }

        $max = $this->maxPerMessage();

        if (count($files) > $max) {
            throw AttachmentException::tooMany($max);
        }

        foreach ($files as $file) {
            $this->assertAcceptable($file);
        }

        $stored = [];

        foreach ($files as $file) {
            $stored[] = $this->store($message, $file);
        }

        return $stored;
    }

    /**
     * Everything attached to a page of messages, in one query.
     *
     * A thread renders every message it has, so the per-message version is an
     * N+1 on the screen people keep open and poll.
     *
     * @param  list<string>  $messageIds
     * @return array<string, list<Attachment>>
     */
    public function forMessages(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $grouped = [];

        foreach (Attachment::query()->whereIn('message_id', $messageIds)->orderBy('created_at')->get() as $row) {
            $grouped[$row->message_id][] = $row;
        }

        return $grouped;
    }

    /** The absolute path, for streaming. Never handed to a client. */
    public function absolutePath(Attachment $attachment): ?string
    {
        $path = Storage::disk($attachment->disk)->path($attachment->path);

        return is_file($path) ? $path : null;
    }

    /** Removes the row and the bytes behind it. */
    public function delete(Attachment $attachment): void
    {
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();
    }

    private function store(Message $message, UploadedFile $file): Attachment
    {
        $disk = $this->diskName();

        $path = $this->disk()->putFileAs(
            'attachments/'.$message->conversation_id,
            $file,
            $this->generatedName($file),
        );

        if ($path === false) {
            throw AttachmentException::storageFailed();
        }

        $attachment = new Attachment;
        $attachment->message_id = $message->id;
        $attachment->disk = $disk;
        $attachment->path = $path;
        $attachment->original_name = Str::limit(basename($file->getClientOriginalName()), 200, '');
        $attachment->mime_type = $this->detectedMime($file);
        $attachment->size_bytes = $file->getSize() === false ? 0 : $file->getSize();
        $attachment->checksum = hash_file('sha256', $file->getRealPath()) ?: '';
        $attachment->save();

        return $attachment;
    }

    /**
     * @throws AttachmentException
     */
    private function assertAcceptable(UploadedFile $file): void
    {
        $maxMb = $this->maxMb();
        $size = $file->getSize();

        if ($size === false || $size <= 0) {
            throw AttachmentException::empty();
        }

        if ($size > $maxMb * 1024 * 1024) {
            throw AttachmentException::tooLarge($maxMb);
        }

        $allowed = $this->allowlist();
        $mime = $this->detectedMime($file);

        // Checked against the sniffed type, so an extension proves nothing.
        if ($allowed !== [] && ! in_array($mime, $allowed, true)) {
            throw AttachmentException::wrongType($mime, $allowed);
        }
    }

    /**
     * The file's real type, from its contents.
     */
    private function detectedMime(UploadedFile $file): string
    {
        $mime = $file->getMimeType();

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    /**
     * A name of our own choosing, keeping a sanitised extension for whoever
     * downloads it later.
     */
    private function generatedName(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?? '';

        return Str::ulid()->toString().($extension === '' ? '' : '.'.$extension);
    }

    /** @return list<string> */
    private function allowlist(): array
    {
        $allowed = config('message-lite.attachments.mime_allowlist', []);

        if (! is_array($allowed)) {
            return [];
        }

        return array_values(array_filter($allowed, is_string(...)));
    }

    private function maxMb(): int
    {
        $value = config('message-lite.attachments.max_mb', 10);

        return is_numeric($value) ? (int) $value : 10;
    }

    private function maxPerMessage(): int
    {
        $value = config('message-lite.attachments.max_per_message', 5);

        return is_numeric($value) ? (int) $value : 5;
    }

    private function diskName(): string
    {
        $name = config('message-lite.attachments.disk', 'message-lite');

        return is_string($name) ? $name : 'message-lite';
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }
}
