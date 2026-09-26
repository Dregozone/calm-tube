<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ArchivedImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One archived image, stored as base64 so it survives a production
 * filesystem that is wiped on every deploy.
 *
 * Written through the `images` disk rather than directly: the rest of the app
 * only ever sees a path, exactly as it did when these were files.
 *
 * @property int $id
 * @property string $path
 * @property string $contents
 * @property string $mime_type
 * @property int $size
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'path',
    'contents',
    'mime_type',
    'size',
])]
class ArchivedImage extends Model
{
    /** @use HasFactory<ArchivedImageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }
}
