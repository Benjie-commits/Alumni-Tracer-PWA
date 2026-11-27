<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A console-controlled switch. A row overrides the config default; no row means the default applies. */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function enabled(string $key, bool $default): bool
    {
        $value = static::query()->whereKey($key)->value('value');

        return $value === null ? $default : $value === '1';
    }

    public static function put(string $key, bool $on): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $on ? '1' : '0']);
    }
}
