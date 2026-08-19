<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations, the people in them, and what was said.
 *
 * The point of this plugin is that it knows nothing about *why* two people are
 * talking. A conversation carries an opaque `context_type` / `context_id`, so
 * one host can point a thread at a service request and the next can point it at
 * an order, a ticket or nothing at all — without either of them teaching this
 * schema what those words mean.
 *
 * Participants are their own table rather than two columns on the conversation.
 * A supervisor joining as an observer is then a row rather than a migration,
 * and read state has somewhere to live.
 *
 * Read state is `last_read_at` per participant, not a row per message per
 * reader. The question people actually ask is "is there anything new", and one
 * row per participant answers it at a fraction of the write volume.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_lite_conversations', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // What the thread is about, in the host's own terms. Nullable
            // because a conversation is allowed to be about nothing.
            $table->string('context_type')->nullable();
            $table->ulid('context_id')->nullable();

            $table->string('subject')->nullable();
            $table->string('status', 32)->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            // The list is always "my threads, most recent first".
            $table->index(['context_type', 'context_id']);
            $table->index(['status', 'last_message_at']);
        });

        Schema::create('message_lite_participants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')
                ->constrained('message_lite_conversations')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();

            // The host's word for why this person is here — "client",
            // "provider", "observer". Free text because this plugin has no
            // opinion about the roles in somebody else's domain.
            $table->string('role', 32)->nullable();

            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('muted_at')->nullable();
            $table->timestamps();

            // Being in a thread twice is not a state worth supporting.
            $table->unique(['conversation_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('message_lite_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')
                ->constrained('message_lite_conversations')->cascadeOnDelete();
            $table->foreignUlid('sender_user_id')->constrained('users')->cascadeOnDelete();

            // A reply points at another message in the same thread. Null on
            // delete rather than cascade: losing the reply because its parent
            // was withdrawn would take somebody's own words with it.
            $table->foreignUlid('reply_to_id')->nullable()
                ->constrained('message_lite_messages')->nullOnDelete();

            $table->text('body');

            // dateTime rather than timestamp: MySQL with
            // explicit_defaults_for_timestamp off gives a NOT NULL TIMESTAMP an
            // implicit '0000-00-00 00:00:00' default that NO_ZERO_DATE then
            // rejects, failing CREATE TABLE with error 1067.
            $table->dateTime('sent_at');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Reads are "everything after this id", which is this index.
            $table->index(['conversation_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_lite_messages');
        Schema::dropIfExists('message_lite_participants');
        Schema::dropIfExists('message_lite_conversations');
    }
};
