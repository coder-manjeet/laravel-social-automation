<?php

namespace CoderManjeet\SocialAutomation\Social;

use Illuminate\Support\Facades\Storage;

class SocialImage
{
    /**
     * Convert an image to a JPEG and return its public URL.
     */
    public static function jpegUrl(string $path): string
    {
        $url = Storage::disk('public')->url(self::store($path, true));

        return str_starts_with($url, 'http') ? $url : url($url);
    }

    /**
     * Convert an image to a JPEG and return its absolute local path.
     */
    public static function jpegPath(string $path, bool $enforceAspectRatio = true): string
    {
        return Storage::disk('public')->path(self::store($path, $enforceAspectRatio));
    }

    private static function store(string $path, bool $enforceAspectRatio): string
    {
        if (! is_file($path)) {
            throw new SocialPublishException('The image file could not be found.');
        }

        $contents = @file_get_contents($path);
        $image = $contents === false ? false : @imagecreatefromstring($contents);

        if ($image === false) {
            throw new SocialPublishException('The image could not be converted to JPEG.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $ratio = $height > 0 ? $width / $height : 0;

        if ($enforceAspectRatio && ($ratio < 0.8 || $ratio > 1.91)) {
            imagedestroy($image);

            throw new SocialPublishException('Instagram needs 4:5 to 1.91:1');
        }

        $relative = 'social/'.sha1_file($path).'.jpg';
        $disk = Storage::disk('public');

        if (! $disk->exists($relative)) {
            $disk->makeDirectory('social');

            $canvas = imagecreatetruecolor($width, $height);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

            ob_start();
            imagejpeg($canvas, null, 85);
            $jpeg = (string) ob_get_clean();

            imagedestroy($canvas);
            imagedestroy($image);

            $disk->put($relative, $jpeg);
        } else {
            imagedestroy($image);
        }

        return $relative;
    }
}
