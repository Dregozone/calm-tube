<?php

namespace Database\Factories;

use App\Models\ArchivedImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArchivedImage>
 */
class ArchivedImageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $bytes = random_bytes(2048);

        return [
            'path' => 'calm-tube/thumbnails/'.fake()->unique()->bothify('???????????').'.jpg',
            'contents' => base64_encode($bytes),
            'mime_type' => 'image/jpeg',
            'size' => strlen($bytes),
        ];
    }
}
