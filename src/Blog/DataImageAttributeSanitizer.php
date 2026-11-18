<?php

namespace CoderManjeet\SocialAutomation\Blog;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Drops inline data URIs unless they are base64-encoded raster images.
 */
final class DataImageAttributeSanitizer implements AttributeSanitizerInterface
{
    /**
     * @var list<string>
     */
    private const ALLOWED_PREFIXES = [
        'data:image/png;base64,',
        'data:image/jpeg;base64,',
        'data:image/jpg;base64,',
        'data:image/webp;base64,',
        'data:image/gif;base64,',
    ];

    public function getSupportedElements(): ?array
    {
        return ['img'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['src'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $normalized = strtolower(trim($value));

        if (! str_starts_with($normalized, 'data:')) {
            return $value;
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return $value;
            }
        }

        return null;
    }
}
