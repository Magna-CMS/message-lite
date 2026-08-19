# Message Lite

Plain text conversations between people, for any Magna plugin that needs them.

Standalone: no `requires` in the manifest, and no dependency on any other
plugin.

## The one idea

**Message Lite does not know what a conversation is about.** A thread carries an
opaque `context_type` / `context_id`, so one host points it at a service request
and the next points it at an order, a ticket, or nothing at all — without either
of them teaching this schema what those words mean.

Everything else follows from that:

- **Who may read or write is the host's decision.** Message Lite's own rule is
  participation — you are in the thread or you are not. A host with richer rules
  binds `DecidesParticipation`.
- **There is no endpoint that opens a conversation.** Threads are opened by the
  host in response to something happening. An endpoint taking two user ids and
  returning a thread would be a way for anybody to write to anybody.

## What it does

| | |
|---|---|
| Threads | opened by the host, optionally about a context, optionally with a subject |
| Participants | many per thread, each with the host's own word for their role |
| Messages | plain text, with replies, edits and withdrawal |
| Read state | `last_read_at` per participant, one query for the unread badge and one for the per-thread breakdown |
| Thread lists | `previewsFor()` — the last message in each of a page of threads, in one query |
| Attachments | files on a message, on a private disk with no public URL, streamed by an authorised endpoint |
| Muting | silence a thread without leaving it |
| Delivery | polling, cursor-based |

Not included, deliberately: reactions, starring, pinning, typing indicators,
disappearing messages. This is the *lite* one.

## Using it from a host plugin

Require it in your manifest:

```json
{ "requires": { "magna/message-lite": "^1.0" } }
```

Open a thread when something happens — never on request:

```php
use Magna\MessageLite\Services\ConversationService;

$conversation = app(ConversationService::class)->open(
    $serviceRequest,                    // the context, in your own terms
    [$clientUserId => 'client', $providerUserId => 'provider'],
    'Rewiring the community library',
);
```

Opening is idempotent on the context *and* the exact set of people, so a second
offer on the same requirement continues the same conversation. Two different
providers answering the same requirement get two threads — folding them together
would put a client's reply to one in front of the other.

### Richer rules

Bind your own participation rules in your plugin's `register()`:

```php
$this->app->bind(DecidesParticipation::class, YourRules::class);
```

`mayRead` and `mayWrite` are asked separately because they genuinely come apart:
an overseer reads without writing, and a finished thread is readable by everyone
in it and writable by nobody.

Nothing is bound by default beyond `ParticipationOnly`, which is the strict
answer rather than the convenient one — a permissive default would make "we
forgot to configure it" and "we meant everyone to read this" look identical from
the outside.

## API

Served at `/api/v1/message-lite`, bearer-token authenticated.

```
GET    /conversations                                  your threads
GET    /conversations/unread                           the badge count
GET    /conversations/{id}                             one thread's state
POST   /conversations/{id}/read                        mark read
POST   /conversations/{id}/muted                       mute or unmute
GET    /conversations/{id}/messages?after={messageId}  poll
POST   /conversations/{id}/messages                    say something
                                                       (multipart: attachments[])
GET    /conversations/{id}/messages/{m}/attachments/{a}  the bytes
PATCH  /conversations/{id}/messages/{message}          edit your own
DELETE /conversations/{id}/messages/{message}          withdraw your own
```

Reads are cursor-based: `after` asks "what has happened since I last looked",
which is one indexed query, where "page one again" is the whole thread every few
seconds.

## Decisions worth knowing about

- **Withdrawal is a soft delete.** The thread reads around the gap rather than
  closing over it, so replies keep their place and their authors keep their
  words. A cascade would delete somebody else's message because of a decision
  they had no part in.
- **An edit is stamped.** A message that can be rewritten with no trace is one
  nobody can rely on having read.
- **A reply pointing at another thread is dropped, not refused.** The message
  still sends; quoting something its readers cannot see would be worse than
  quoting nothing.
- **The unread cursor is tie-broken on the id.** Two messages can share a
  second, and ordering on the timestamp alone would drop one of them for ever.
- **A thread list costs three queries, not three per row.** `unreadPerThread()`
  and `previewsFor()` both take a page of ids and answer for all of them at
  once — a thread list is the screen most likely to grow an N+1, because the
  per-row version is the one that reads naturally. `previewsFor()` picks the
  latest message with `MAX(id)` rather than `MAX(sent_at)`: ids are ULIDs and
  sort in the order they were minted, where a timestamp ties whenever two
  messages share a second and returns two rows for one thread.
- **Only your own words.** Editing or withdrawing somebody else's message is not
  a permission this plugin can grant, because there is no reading of it that is
  not putting words in their mouth.
- **Polling, not websockets.** Magna has no broadcasting layer; adding one is a
  core decision, and this plugin works without it.
- **An attachment is authorised on its thread, and never has a URL.** Whether
  you may see a file is entirely a question of whether you may read the
  conversation it was sent in — the host's answer, through the same contract.
  So the disk has no public URL and the bytes are streamed by a controller that
  asks again on every read. `Content-Disposition: attachment` is forced: an HTML
  or SVG served inline from this origin is a stored XSS against everybody in the
  thread, which is also why SVG is not on the allowlist.
- **The stored name is generated and the type is sniffed.** A file called
  `../../.env` must not get to decide where anything lands, and renaming a
  script to `.pdf` must not get it past the allowlist. `config/message-lite.php`
  holds the disk, the size cap and the allowed types.
- **Whether attachments are offered is the host's call, not this plugin's.**
  The config here is the *limits*. EMBHAS, for example, has its own operator
  setting, and this plugin never second-guesses it.

## Checks

```bash
# from the CMS root
vendor/bin/pint --test plugins-dev/magna/message-lite
vendor/bin/phpstan analyse -c plugins-dev/magna/message-lite/phpstan.neon.dist
vendor/bin/pest plugins-dev/magna/message-lite/tests
```

14 feature tests cover the things that would be expensive to get wrong: thread
identity, the unread count, the polling cursor including the shared-second case,
participation refusals, a closed thread staying readable, withdrawal leaving
replies intact, cross-thread replies, and the absence of a create-conversation
endpoint.
