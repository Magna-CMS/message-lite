<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Magna\MessageLite\Http\Controllers\ConversationController;
use Magna\MessageLite\Http\Controllers\MessageController;

/*
|--------------------------------------------------------------------------
| Message Lite
|--------------------------------------------------------------------------
| Core mounts this at /api/v1/message-lite with the 'api' middleware group, so
| the paths here are relative and the plugin must not mount it again.
|
| There is deliberately no route that opens a conversation. Threads are opened
| by the host in response to something happening — see ConversationService.
| An endpoint taking two user ids and returning a thread would be a way for
| anybody to write to anybody.
*/

Route::middleware('magna.api:management')->name('message-lite.')->group(function (): void {
    Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');

    // Before the wildcard, or "unread" is read as a conversation id.
    Route::get('/conversations/unread', [ConversationController::class, 'unread'])->name('unread');

    Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])
        ->name('conversations.show');
    Route::post('/conversations/{conversation}/read', [ConversationController::class, 'markRead'])
        ->name('conversations.read');
    Route::post('/conversations/{conversation}/muted', [ConversationController::class, 'setMuted'])
        ->name('conversations.muted');

    Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index'])
        ->name('messages.index');

    // Rate-limited on its own: this is the one endpoint here that creates rows,
    // and a runaway client should not be able to fill a thread.
    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('messages.store');

    // The only path to an attachment's bytes. Authorised on the thread, and
    // streamed — the disk has no public URL, so there is nothing to link to.
    Route::get(
        '/conversations/{conversation}/messages/{message}/attachments/{attachment}',
        [MessageController::class, 'download'],
    )->name('attachments.download');

    Route::patch('/conversations/{conversation}/messages/{message}', [MessageController::class, 'update'])
        ->name('messages.update');
    Route::delete('/conversations/{conversation}/messages/{message}', [MessageController::class, 'destroy'])
        ->name('messages.destroy');
});
