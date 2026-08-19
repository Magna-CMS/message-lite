<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    | These are the *limits*, not the switch. Whether a given product offers
    | attachments at all is the host's decision — EMBHAS, for example, has its
    | own `message_attachments_enabled` setting that an operator changes from
    | the admin screen, and this plugin never second-guesses it.
    |
    | What lives here is the infrastructure a host should not have to reinvent:
    | which disk, how big, and which types are allowed. The allowlist is matched
    | against the type sniffed from the file's own bytes, never the extension or
    | the browser-supplied Content-Type.
    |
    | The disk deliberately has no `url`. A message attachment is somebody's
    | private correspondence; it is served by an authorised controller that asks
    | the host whether this reader may see the thread, and it must never be
    | reachable by guessing a path.
    */
    'attachments' => [
        'disk' => 'message-lite',
        'root' => storage_path('app/message-lite'),
        'max_mb' => 10,
        'max_per_message' => 5,

        /*
        | Documents and images. Deliberately no SVG: it is a script container
        | that browsers execute, so serving one back to a reader is a stored
        | XSS with extra steps. Deliberately no archives either — nobody can
        | tell what is inside one before opening it.
        */
        'mime_allowlist' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/heic',
            'text/plain',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
    ],

];
