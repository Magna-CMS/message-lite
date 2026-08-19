<?php

declare(strict_types=1);

namespace Magna\MessageLite;

use Magna\Contracts\MediaSource;
use Magna\Contracts\RegistersMediaSources;
use Magna\MessageLite\Contracts\DecidesParticipation;
use Magna\MessageLite\Media\AttachmentsMediaSource;
use Magna\MessageLite\Services\AttachmentService;
use Magna\MessageLite\Services\ConversationService;
use Magna\MessageLite\Services\MessageService;
use Magna\MessageLite\Support\ParticipationOnly;
use Magna\Plugins\Plugin;

/**
 * Message Lite — plain text conversations, for any plugin that needs them.
 *
 * It knows nothing about what a conversation is *about*. Threads carry an
 * opaque context, and who may read or write one is a question this plugin asks
 * the host rather than answers itself. That is the whole design: a messaging
 * plugin with an opinion about empanelment, or orders, or tickets, would be a
 * messaging plugin for exactly one application.
 *
 * Routes are not mounted here. Core's PluginRouteRegistrar picks up
 * routes/api.php and serves it at /api/v1/message-lite; mounting it again would
 * register every route twice.
 */
class MessageLitePlugin extends Plugin implements RegistersMediaSources
{
    /**
     * Attachments sit on this plugin's own private disk, so the media library
     * has no other way to know they exist. See AttachmentsMediaSource for why
     * they are counted there and never opened there.
     *
     * @return list<MediaSource>
     */
    public function mediaSources(): array
    {
        return [new AttachmentsMediaSource];
    }

    public function register(): void
    {
        $this->mergeConfigFrom('config/message-lite.php', 'message-lite');

        $this->app->singleton(ConversationService::class);
        $this->app->singleton(MessageService::class);
        $this->app->singleton(AttachmentService::class);

        $this->registerAttachmentDisk();

        // Bound only if the host has not. A host with richer rules — an
        // overseer who reads without writing, a thread that closes with its
        // work — binds its own implementation in its own provider, and this
        // never overwrites it.
        $this->app->bindIf(DecidesParticipation::class, ParticipationOnly::class);
    }

    /**
     * A private disk for attachments, unless the site has already defined one.
     *
     * Never the public disk and never core Media: those are CMS assets served
     * by URL, and a message attachment is somebody's private correspondence.
     * Skipped when a disk of this name already exists, so a site backing
     * attachments with S3 keeps its own configuration.
     */
    private function registerAttachmentDisk(): void
    {
        $disk = config('message-lite.attachments.disk', 'message-lite');
        $name = is_string($disk) ? $disk : 'message-lite';

        if (config("filesystems.disks.{$name}") !== null) {
            return;
        }

        $root = config('message-lite.attachments.root');

        config([
            "filesystems.disks.{$name}" => [
                'driver' => 'local',
                'root' => is_string($root) ? $root : storage_path('app/message-lite'),
                'throw' => false,
                // No 'url' key on purpose: there is no public URL for these.
            ],
        ]);
    }
}
