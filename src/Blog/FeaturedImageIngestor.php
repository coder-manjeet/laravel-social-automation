<?php

namespace CoderManjeet\SocialAutomation\Blog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use CoderManjeet\SocialAutomation\Models\McpUpload;

class FeaturedImageIngestor
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MIN_WIDTH = 1200;

    public const MIN_HEIGHT = 630;

    /**
     * @var list<string>
     */
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Validate and store a base64 featured image on a temporary MCP upload.
     */
    public function ingest(
        string $imageBase64,
        string $filename,
        string $alt,
        string $mimeType,
        Model $user,
    ): McpUpload {
        $this->assertAlt($alt);

        $binary = $this->decode($imageBase64);

        if (strlen($binary) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'image_base64' => 'The image must be 5 MB or smaller after decoding.',
            ]);
        }

        $info = @getimagesizefromstring($binary);

        if ($info === false) {
            throw ValidationException::withMessages([
                'image_base64' => 'The image data could not be read. Send a valid base64 encoded image.',
            ]);
        }

        $actualMime = image_type_to_mime_type($info[2]);

        if (! in_array($actualMime, self::ALLOWED_MIME_TYPES, true)) {
            throw ValidationException::withMessages([
                'image_base64' => 'Only JPEG, PNG and WebP images are allowed.',
            ]);
        }

        if ($this->normaliseMime($mimeType) !== $actualMime) {
            throw ValidationException::withMessages([
                'mime_type' => "The declared mime type does not match the image ({$actualMime}).",
            ]);
        }

        [$width, $height] = $info;

        if ($width < self::MIN_WIDTH || $height < self::MIN_HEIGHT) {
            throw ValidationException::withMessages([
                'image_base64' => 'The image must be at least 1200x630 pixels.',
            ]);
        }

        $name = Str::slug(pathinfo($filename, PATHINFO_FILENAME)) ?: 'featured-image';

        $upload = McpUpload::create([
            'user_id' => $user->id,
        ]);

        $upload->addMediaFromString($binary)
            ->usingFileName($name.'.'.$this->extensionFor($actualMime))
            ->usingName($name)
            ->withCustomProperties(['alt' => $alt])
            ->toMediaCollection('featured_image');

        return $upload->refresh();
    }

    private function assertAlt(string $alt): void
    {
        $alt = trim($alt);

        if ($alt === '') {
            throw ValidationException::withMessages([
                'alt' => 'The image alt text is required.',
            ]);
        }

        if (mb_strlen($alt) > 255) {
            throw ValidationException::withMessages([
                'alt' => 'The image alt text may not be longer than 255 characters.',
            ]);
        }
    }

    private function decode(string $imageBase64): string
    {
        $value = trim($imageBase64);

        if (str_starts_with($value, 'data:')) {
            $value = Str::after($value, ',');
        }

        $value = preg_replace('/\s+/', '', $value) ?? '';
        $binary = base64_decode($value, true);

        if ($binary === false || $binary === '') {
            throw ValidationException::withMessages([
                'image_base64' => 'The image is not valid base64 data.',
            ]);
        }

        return $binary;
    }

    private function normaliseMime(string $mimeType): string
    {
        $mimeType = strtolower(trim($mimeType));

        return $mimeType === 'image/jpg' ? 'image/jpeg' : $mimeType;
    }

    private function extensionFor(string $mimeType): string
    {
        return match ($mimeType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
