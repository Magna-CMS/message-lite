<?php

declare(strict_types=1);

namespace Magna\MessageLite\Support;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A model's key, as a string.
 *
 * Eloquent types `getKey()` as mixed, because a key can be an int, a string or
 * — on an unsaved model — null. This plugin stores ULIDs and references the
 * host's users, so the honest conversion is "a string if there is one, null if
 * there isn't", written once here rather than as a bare `(string)` cast at
 * every call site where a null key would quietly become the empty string and be
 * stored as a foreign key pointing at nothing.
 */
final class ModelId
{
    public static function of(Model $model): ?string
    {
        $key = $model->getKey();

        return match (true) {
            is_string($key) => $key,
            is_int($key) => (string) $key,
            default => null,
        };
    }

    /**
     * The same, for a caller that cannot proceed without one — a saved model
     * always has a key, and an unsaved one reaching here is a bug worth hearing
     * about rather than a silent empty string.
     */
    public static function require(Model $model): string
    {
        $id = self::of($model);

        if ($id === null) {
            throw new RuntimeException(sprintf(
                'Expected %s to have been saved before its id was used.',
                $model::class,
            ));
        }

        return $id;
    }
}
