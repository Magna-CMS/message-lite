<?php

declare(strict_types=1);

namespace Magna\MessageLite\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

/**
 * The uploaded files on a request, as a flat list.
 *
 * Flattened rather than read straight off `file()`, because the shape of that
 * key is decided by whoever sent the request: `attachments` may arrive as one
 * file, a list, or a nested array. A caller that assumed a flat list of
 * UploadedFile would hand an array to something expecting a file.
 *
 * Anything that is not an UploadedFile is dropped rather than refused. The
 * validator downstream is what reports a bad upload; this is only concerned
 * with not passing junk to the storage layer.
 *
 * Shared so the host and this plugin read the same request the same way — two
 * copies of "how do I get the files out" is two places for the nesting case to
 * be handled differently.
 */
final class UploadList
{
    /**
     * @return list<UploadedFile>
     */
    public static function from(Request $request, string $key = 'attachments'): array
    {
        $files = [];

        foreach (Arr::flatten([$request->allFiles()[$key] ?? []]) as $candidate) {
            if ($candidate instanceof UploadedFile) {
                $files[] = $candidate;
            }
        }

        return $files;
    }
}
