<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Files hanging off a message.
     *
     * Here rather than in the host plugin, because a file attached to a message
     * is part of what a message *is* — and every host that wants attachments
     * would otherwise write the same table, the same private-disk rules and the
     * same authorised download endpoint again, slightly differently.
     *
     * What is stored is a pointer, never the bytes: `disk` and `path` name a
     * location on a filesystem with no public URL, so nothing here is reachable
     * by guessing. `original_name` is a label for the download header and is
     * never used to build a path — a file called `../../.env` must not get to
     * decide where anything lands.
     *
     * `mime_type` is the type sniffed from the file's own contents, not the
     * browser-supplied Content-Type, which is attacker-controlled.
     */
    public function up(): void
    {
        Schema::create('message_lite_attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Cascades because an attachment has no meaning without its
            // message. Messages are soft-deleted, so withdrawing one leaves the
            // rows in place and the download endpoint refuses them — this fires
            // only on a genuine hard delete, such as a purge.
            $table->foreignUlid('message_id')
                ->constrained('message_lite_messages')
                ->cascadeOnDelete();

            $table->string('disk', 64);
            $table->string('path', 500);
            $table->string('original_name', 200);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');

            // Tells "corrupted in storage" from "replaced", and lets a host
            // de-duplicate repeat uploads of the same file.
            $table->string('checksum', 64);

            $table->timestamps();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_lite_attachments');
    }
};
