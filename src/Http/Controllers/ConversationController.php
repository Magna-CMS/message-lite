<?php

declare(strict_types=1);

namespace Magna\MessageLite\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Magna\MessageLite\Models\Conversation;
use Magna\MessageLite\Services\ConversationService;
use Magna\MessageLite\Support\ModelId;
use Magna\Users\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * The thread list, and one thread's state.
 *
 * There is no endpoint here that opens a conversation. Threads are opened by
 * the host in response to something happening, never by a client asking for
 * one — see ConversationService. An endpoint that took two user ids and made a
 * thread would be a way for anybody to write to anybody.
 */
final class ConversationController extends Controller
{
    public function __construct(private readonly ConversationService $conversations) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $threads = $this->conversations->visibleTo($user)
            ->with('participants.user:id,name')
            ->paginate(25);

        $threads->getCollection()->transform(fn (Conversation $thread): array => [
            'id' => $thread->id,
            'subject' => $thread->subject,
            'status' => $thread->status,
            'context_type' => $thread->context_type,
            'context_id' => $thread->context_id,
            'last_message_at' => $thread->last_message_at?->toIso8601String(),
            'muted' => $thread->participantFor($user)?->isMuted() ?? false,
            // Everybody else in the thread. The reader knows who they are.
            'participants' => $thread->participants
                ->reject(fn ($participant): bool => $participant->user_id === ModelId::require($user))
                ->map(fn ($participant): array => [
                    'id' => $participant->user_id,
                    'name' => $participant->user?->name,
                    'role' => $participant->role,
                ])
                ->values()
                ->all(),
        ]);

        return response()->json($threads);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->user($request);
        $this->assertMayRead($user, $conversation);

        return response()->json([
            'data' => [
                'id' => $conversation->id,
                'subject' => $conversation->subject,
                'status' => $conversation->status,
                'context_type' => $conversation->context_type,
                'context_id' => $conversation->context_id,
                'may_write' => $this->conversations->mayWrite($user, $conversation),
                'muted' => $conversation->participantFor($user)?->isMuted() ?? false,
            ],
        ]);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->user($request);
        $this->assertMayRead($user, $conversation);

        $this->conversations->markRead($conversation, $user);

        return response()->json(['unread' => $this->conversations->unreadCount($user)]);
    }

    /** Silence a thread without leaving it. */
    public function setMuted(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->user($request);
        $this->assertMayRead($user, $conversation);

        $muted = $request->boolean('muted');

        $this->conversations->setMuted($conversation, $user, $muted);

        return response()->json(['muted' => $muted]);
    }

    public function unread(Request $request): JsonResponse
    {
        return response()->json(['unread' => $this->conversations->unreadCount($this->user($request))]);
    }

    /**
     * A refusal, not an empty list.
     *
     * "Nothing was said" and "this is not yours to read" are different answers,
     * and returning the first for the second tells somebody a thread exists.
     */
    private function assertMayRead(User $user, Conversation $conversation): void
    {
        abort_unless(
            $this->conversations->mayRead($user, $conversation),
            Response::HTTP_FORBIDDEN,
            'That conversation is not yours to read.',
        );
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        return $user;
    }
}
