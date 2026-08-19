<?php

declare(strict_types=1);

namespace Magna\MessageLite\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Magna\MessageLite\Models\Attachment;
use Magna\MessageLite\Models\Conversation;
use Magna\MessageLite\Models\Message;
use Magna\MessageLite\Services\AttachmentService;
use Magna\MessageLite\Services\ConversationService;
use Magna\MessageLite\Services\MessageService;
use Magna\MessageLite\Support\ModelId;
use Magna\MessageLite\Support\UploadList;
use Magna\Users\User;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reading and writing inside one thread.
 *
 * Every method asks the host whether this person may read or write before it
 * does anything — see DecidesParticipation. Reading and writing are asked
 * separately because they genuinely come apart: an overseer reads without
 * writing, and a finished thread is readable by everyone in it and writable by
 * nobody.
 */
final class MessageController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly AttachmentService $attachments,
    ) {}

    /**
     * The thread, or whatever is new in it.
     *
     * `after` makes this a poll: one indexed query for "what has happened since
     * I last looked", rather than the whole thread every few seconds.
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->reader($request, $conversation);

        $after = $request->string('after')->toString();

        $messages = $after === ''
            ? $this->messages->latest($conversation)
            : $this->messages->since($conversation, $after);

        // Reading the thread is what marks it read. A separate call to say so
        // is one the client can forget to make.
        $this->conversations->markRead($conversation, $user);

        // One query for the page rather than one per message: a thread renders
        // everything it has and is polled every few seconds, so this is the
        // easiest place in the plugin to grow an N+1.
        $ids = [];

        foreach ($messages as $message) {
            $ids[] = $message->id;
        }

        $attachments = $this->attachments->forMessages($ids);

        return response()->json([
            'data' => $messages
                ->map(fn (Message $message): array => $this->present($message, $attachments[$message->id] ?? []))
                ->all(),
        ]);
    }

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->writer($request, $conversation);

        $files = UploadList::from($request);

        $data = $request->validate([
            // A message carrying a file needs no words: "here is the invoice"
            // is a complete thought when the invoice is attached. Required
            // otherwise, or an empty post is a blank line in the thread.
            'body' => [$files === [] ? 'required' : 'nullable', 'string', 'max:5000'],
            'reply_to_id' => ['nullable', 'string'],
            'attachments' => ['sometimes', 'array'],
            'attachments.*' => ['file'],
        ]);

        $message = $this->messages->post(
            $conversation,
            $user,
            $data['body'] ?? '',
            $data['reply_to_id'] ?? null,
        );

        // After the message exists, because an attachment needs something to
        // hang off. A refusal here throws, and the transaction the post ran in
        // has already committed — so the message stands and the sender is told
        // which file was refused, rather than losing what they wrote.
        $this->attachments->attach($message, $files);

        return response()->json(
            ['data' => $this->present($message->load('attachments'))],
            Response::HTTP_CREATED,
        );
    }

    /**
     * Hand back one attachment's bytes.
     *
     * Authorised on the *thread*, not on the attachment: whether you may see a
     * file is entirely a question of whether you may read the conversation it
     * was sent in, and that is the host's answer through DecidesParticipation.
     *
     * Streamed from the private disk rather than redirected to a URL. There is
     * no URL — that is the point.
     */
    public function download(
        Request $request,
        Conversation $conversation,
        Message $message,
        Attachment $attachment,
    ): BinaryFileResponse {
        $this->reader($request, $conversation);

        // Both hops checked. Without them, a valid attachment id from any
        // thread would be served to anybody who could read any other thread.
        abort_unless($message->conversation_id === $conversation->id, Response::HTTP_NOT_FOUND);
        abort_unless($attachment->message_id === $message->id, Response::HTTP_NOT_FOUND);

        // A withdrawn message takes its files with it. The row survives so the
        // thread reads around the gap; the bytes stop being served.
        abort_if($message->trashed(), Response::HTTP_GONE, 'That message was withdrawn.');

        $path = $this->attachments->absolutePath($attachment);

        abort_if($path === null, Response::HTTP_NOT_FOUND);

        return response()->download($path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            // Never inline. An HTML or SVG file served inline from this origin
            // is a stored XSS against everybody in the thread.
            'Content-Disposition' => 'attachment',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        $user = $this->writer($request, $conversation);
        $this->assertOwn($user, $conversation, $message);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        return response()->json(['data' => $this->present($this->messages->edit($message, $data['body']))]);
    }

    public function destroy(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        $user = $this->writer($request, $conversation);
        $this->assertOwn($user, $conversation, $message);

        $this->messages->withdraw($message);

        return response()->json(['message' => 'Message withdrawn.']);
    }

    /**
     * @param  list<Attachment>|null  $attachments  already loaded, to avoid an N+1
     * @return array<string, mixed>
     */
    private function present(Message $message, ?array $attachments = null): array
    {
        $files = $attachments ?? $message->attachments->all();

        return [
            'id' => $message->id,
            'body' => $message->body,
            'sender_id' => $message->sender_user_id,
            'sender_name' => $message->sender?->name,
            'reply_to_id' => $message->reply_to_id,
            'sent_at' => $message->sent_at->toIso8601String(),
            'edited_at' => $message->edited_at?->toIso8601String(),
            // No URL and no path: the disk has neither. What a client gets is
            // an id to ask the download endpoint for, which re-checks who is
            // asking every time.
            'attachments' => array_map(fn (Attachment $file): array => [
                'id' => $file->id,
                'name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'size_bytes' => $file->size_bytes,
                'is_image' => $file->isImage(),
            ], $files),
        ];
    }

    /**
     * Only your own words.
     *
     * Editing or withdrawing somebody else's message is not a permission this
     * plugin has a way to grant, because there is no reading of it that is not
     * putting words in their mouth.
     */
    private function assertOwn(User $user, Conversation $conversation, Message $message): void
    {
        abort_unless($message->conversation_id === $conversation->id, Response::HTTP_NOT_FOUND);

        abort_unless(
            $message->sender_user_id === ModelId::require($user),
            Response::HTTP_FORBIDDEN,
            'Only the person who wrote a message can change it.',
        );
    }

    private function reader(Request $request, Conversation $conversation): User
    {
        $user = $this->user($request);

        abort_unless(
            $this->conversations->mayRead($user, $conversation),
            Response::HTTP_FORBIDDEN,
            'That conversation is not yours to read.',
        );

        return $user;
    }

    private function writer(Request $request, Conversation $conversation): User
    {
        $user = $this->user($request);

        abort_unless(
            $this->conversations->mayWrite($user, $conversation),
            Response::HTTP_FORBIDDEN,
            'That conversation is not yours to write to.',
        );

        return $user;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        return $user;
    }
}
