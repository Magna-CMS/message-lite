<?php

declare(strict_types=1);

use Magna\Auth\MagnaToken;
use Magna\MessageLite\Models\Conversation;
use Magna\MessageLite\Models\Message;
use Magna\MessageLite\Services\ConversationService;
use Magna\MessageLite\Services\MessageService;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use Magna\Users\UserStatus;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/message-lite');
});

function messageLiteUser(string $email): User
{
    return User::factory()->create(['email' => $email, 'status' => UserStatus::Active]);
}

/**
 * A real bearer token, because `actingAs()` will not do here.
 *
 * `actingAs()` sets the web guard, and core's `magna.api` middleware neither
 * sees nor trusts it — the middleware resolves its own token and checks both
 * the `scope` column and the Sanctum ability, so the two have to be written in
 * lockstep or it fails closed with a 403 that explains nothing.
 *
 * @return array<string, string>
 */
function messageLiteBearer(User $user): array
{
    $issued = $user->createToken('test', ['management']);

    /** @var MagnaToken $token */
    $token = $issued->accessToken;
    $token->forceFill(['scope' => 'management'])->save();

    return ['Authorization' => 'Bearer '.$issued->plainTextToken];
}

/** Two people already talking. */
function messageLiteThread(): array
{
    $alice = messageLiteUser('alice@message-lite.test');
    $bob = messageLiteUser('bob@message-lite.test');

    $conversation = app(ConversationService::class)->open(null, [
        (string) $alice->getKey() => 'client',
        (string) $bob->getKey() => 'provider',
    ], 'About the wiring');

    return [$conversation, $alice, $bob];
}

it('opens one thread per set of people about one thing', function (): void {
    $alice = messageLiteUser('a@message-lite.test');
    $bob = messageLiteUser('b@message-lite.test');
    $carol = messageLiteUser('c@message-lite.test');

    $context = messageLiteUser('context@message-lite.test');
    $service = app(ConversationService::class);

    $first = $service->open($context, [(string) $alice->getKey() => null, (string) $bob->getKey() => null]);
    $again = $service->open($context, [(string) $alice->getKey() => null, (string) $bob->getKey() => null]);

    // The same pair about the same thing continues the same conversation
    // rather than starting a parallel one nobody reads.
    expect($again->id)->toBe($first->id);

    $other = $service->open($context, [(string) $alice->getKey() => null, (string) $carol->getKey() => null]);

    // A different pair is a different thread, even about the same thing —
    // folding them together would put a reply to one in front of the other.
    expect($other->id)->not->toBe($first->id);
});

it('counts unread messages from other people only', function (): void {
    [$conversation, $alice, $bob] = messageLiteThread();

    $messages = app(MessageService::class);
    $conversations = app(ConversationService::class);

    $messages->post($conversation, $alice, 'Are you free on Saturday?');

    expect($conversations->unreadCount($bob))->toBe(1)
        // Nobody has unread mail from themselves.
        ->and($conversations->unreadCount($alice))->toBe(0);

    $conversations->markRead($conversation, $bob);

    expect($conversations->unreadCount($bob))->toBe(0);
});

it('reads only what is new when given a cursor', function (): void {
    [$conversation, $alice, $bob] = messageLiteThread();

    $messages = app(MessageService::class);

    $first = $messages->post($conversation, $alice, 'One');
    $messages->post($conversation, $bob, 'Two');
    $messages->post($conversation, $alice, 'Three');

    $since = $messages->since($conversation, $first->id);

    // The poll asks "what has happened since", not "the whole thread again".
    expect($since->pluck('body')->all())->toBe(['Two', 'Three']);
});

it('does not lose a message that shares a second with the cursor', function (): void {
    [$conversation, $alice, $bob] = messageLiteThread();

    $messages = app(MessageService::class);

    $first = $messages->post($conversation, $alice, 'One');

    // Same instant. Ordering on the timestamp alone would drop this for ever,
    // because it is not strictly *after* the cursor.
    $second = $messages->post($conversation, $bob, 'Two');
    $second->sent_at = $first->sent_at;
    $second->save();

    expect(app(MessageService::class)->since($conversation, $first->id)->pluck('body')->all())
        ->toContain('Two');
});

it('refuses a thread to somebody who is not in it', function (): void {
    [$conversation, $alice] = messageLiteThread();

    $outsider = messageLiteUser('outsider@message-lite.test');

    $conversations = app(ConversationService::class);

    expect($conversations->mayRead($alice, $conversation))->toBeTrue()
        // Participation is the whole of the default rule.
        ->and($conversations->mayRead($outsider, $conversation))->toBeFalse()
        ->and($conversations->mayWrite($outsider, $conversation))->toBeFalse();
});

it('lets a closed thread be read but not written to', function (): void {
    [$conversation, $alice] = messageLiteThread();

    $conversations = app(ConversationService::class);
    $conversations->close($conversation);

    // Ending a conversation is not the same as withdrawing what was said in it.
    expect($conversations->mayRead($alice, $conversation->fresh() ?? $conversation))->toBeTrue()
        ->and($conversations->mayWrite($alice, $conversation->fresh() ?? $conversation))->toBeFalse();
});

it('keeps a reply readable after the message it answered is withdrawn', function (): void {
    [$conversation, $alice, $bob] = messageLiteThread();

    $messages = app(MessageService::class);

    $question = $messages->post($conversation, $alice, 'What time?');
    $answer = $messages->post($conversation, $bob, 'Ten o’clock.', $question->id);

    $messages->withdraw($question);

    // The reply keeps its place and its author keeps their words. A cascade
    // here would delete somebody else's message because of a decision they
    // had no part in.
    expect(Message::query()->whereKey($answer->id)->exists())->toBeTrue()
        ->and(Message::query()->whereKey($question->id)->exists())->toBeFalse();
});

it('ignores a reply pointing at another thread', function (): void {
    [$first, $alice, $bob] = messageLiteThread();

    $elsewhere = app(ConversationService::class)->open(null, [
        (string) $alice->getKey() => null,
    ], 'Another matter');

    $messages = app(MessageService::class);
    $stray = $messages->post($elsewhere, $alice, 'Unrelated');

    $reply = $messages->post($first, $bob, 'Replying', $stray->id);

    // Quoting something this thread's readers cannot see would be worse than
    // quoting nothing, so the link is dropped and the message still sends.
    expect($reply->reply_to_id)->toBeNull()
        ->and($reply->body)->toBe('Replying');
});

it('stamps an edit rather than changing a message silently', function (): void {
    [$conversation, $alice] = messageLiteThread();

    $messages = app(MessageService::class);
    $message = $messages->post($conversation, $alice, 'Sartuday');

    expect($message->wasEdited())->toBeFalse();

    $edited = $messages->edit($message, 'Saturday');

    // A message that can be rewritten with no trace is one nobody can rely on
    // having read.
    expect($edited->wasEdited())->toBeTrue()
        ->and($edited->body)->toBe('Saturday');
});

it('moves a thread to the top of the list when something is said', function (): void {
    [$conversation, $alice, $bob] = messageLiteThread();

    expect($conversation->last_message_at)->toBeNull();

    app(MessageService::class)->post($conversation, $alice, 'Hello');

    $threads = app(ConversationService::class)->visibleTo($bob)->get();

    expect($threads->first()?->last_message_at)->not->toBeNull();
});

it('adds somebody to a thread without resetting what they had read', function (): void {
    [$conversation, $alice, $bob] = messageLiteThread();

    $conversations = app(ConversationService::class);

    $conversations->markRead($conversation, $bob);
    $before = $conversation->participants()->where('user_id', $bob->getKey())->value('last_read_at');

    $conversations->addParticipant($conversation, (string) $bob->getKey(), 'provider');

    $after = $conversation->participants()->where('user_id', $bob->getKey())->value('last_read_at');

    // Joining again is not a reason to mark somebody's correspondence unread.
    expect($after)->toEqual($before)
        ->and($conversation->participants()->where('user_id', $bob->getKey())->count())->toBe(1);
});

it('refuses the API to a caller with no session', function (): void {
    $this->getJson('/api/v1/message-lite/conversations')->assertUnauthorized();
});

it('will not let one person edit another person’s message', function (): void {
    [$conversation, $alice, $bob] = messageLiteThread();

    $message = app(MessageService::class)->post($conversation, $alice, 'Mine');

    $this->withHeaders(messageLiteBearer($bob))
        ->patchJson(
            "/api/v1/message-lite/conversations/{$conversation->id}/messages/{$message->id}",
            ['body' => 'Not yours'],
        )
        ->assertForbidden();

    expect(Message::query()->whereKey($message->id)->value('body'))->toBe('Mine');
});

it('has no endpoint that opens a conversation', function (): void {
    // Deliberate. An endpoint taking two user ids and returning a thread would
    // be a way for anybody to write to anybody; threads are opened by the host
    // in response to something happening.
    $this->postJson('/api/v1/message-lite/conversations', [])->assertStatus(405);

    expect(Conversation::query()->count())->toBe(0);
});
