<?php

namespace CoderManjeet\SocialAutomation\Mcp\Tools\Blog;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use CoderManjeet\SocialAutomation\Blog\FeaturedImageIngestor;
use Throwable;

#[Name('upload_featured_image')]
#[Description('Upload a featured image from base64 data. Returns a media_id that create_post or update_post can attach to the post. The image must be JPEG, PNG or WebP, at least 1200x630 pixels and no more than 5 MB.')]
class UploadFeaturedImageTool extends BlogTool
{
    protected function execute(Request $request): Response
    {
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'alt' => ['required', 'string', 'max:255'],
            'image_base64' => ['required', 'string'],
            'mime_type' => ['required', 'string', 'in:image/jpeg,image/png,image/webp'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $upload = app(FeaturedImageIngestor::class)->ingest(
            $data['image_base64'],
            $data['filename'],
            $data['alt'],
            $data['mime_type'],
            $user,
        );

        $media = $upload->getFirstMedia('featured_image');

        if ($media === null) {
            return Response::error('The image could not be stored. Upload it again.');
        }

        [$width, $height] = $this->dimensions($media->disk, $media->getPathRelativeToRoot());

        return $this->json([
            'media_id' => $media->id,
            'url' => $media->getUrl(),
            'width' => $width,
            'height' => $height,
            'size_kb' => round((int) $media->size / 1024, 1),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'filename' => $schema->string()
                ->required()
                ->max(120)
                ->pattern('^[a-z0-9]+(?:-[a-z0-9]+)*$')
                ->description('Slug-like file name without the extension, for example erp-inventory-dashboard.'),
            'alt' => $schema->string()
                ->required()
                ->max(255)
                ->description('Accessible alt text describing the image.'),
            'image_base64' => $schema->string()
                ->required()
                ->description('The image file encoded as base64. A data URI prefix is also accepted.'),
            'mime_type' => $schema->string()
                ->required()
                ->enum(['image/jpeg', 'image/png', 'image/webp'])
                ->description('The MIME type of the encoded image.'),
        ];
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensions(string $disk, string $path): array
    {
        try {
            $contents = Storage::disk($disk)->get($path);

            if (! is_string($contents)) {
                return [null, null];
            }

            $size = @getimagesizefromstring($contents);

            if ($size === false) {
                return [null, null];
            }

            return [(int) $size[0], (int) $size[1]];
        } catch (Throwable) {
            return [null, null];
        }
    }
}
