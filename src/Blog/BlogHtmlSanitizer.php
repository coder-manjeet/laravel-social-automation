<?php

namespace CoderManjeet\SocialAutomation\Blog;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class BlogHtmlSanitizer
{
    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->withMaxInputLength(200_000)
            ->defaultAction(HtmlSanitizerAction::Block)
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowMediaSchemes(['http', 'https', 'data'])
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('h4')
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('u')
            ->allowElement('s')
            ->allowElement('a', ['href', 'title', 'target', 'rel'])
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('blockquote')
            ->allowElement('table')
            ->allowElement('thead')
            ->allowElement('tbody')
            ->allowElement('tr')
            ->allowElement('th')
            ->allowElement('td')
            ->allowElement('hr')
            ->allowElement('code')
            ->allowElement('pre')
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height'])
            ->allowElement('figure')
            ->allowElement('figcaption')
            ->dropElement('script')
            ->dropElement('style')
            ->dropElement('iframe')
            ->dropElement('object')
            ->dropElement('embed')
            ->dropElement('form')
            ->dropElement('input')
            ->withAttributeSanitizer(new DataImageAttributeSanitizer);

        $this->sanitizer = new HtmlSanitizer($config);
    }

    /**
     * Sanitize post HTML using the blog allowlist.
     */
    public function sanitize(?string $html): string
    {
        $html = (string) $html;

        if ($html === '') {
            return '';
        }

        return $this->secureBlankTargets($this->sanitizer->sanitize($html));
    }

    /**
     * Force noopener/noreferrer on links that open a new tab.
     */
    private function secureBlankTargets(string $html): string
    {
        return preg_replace_callback('/<a\b[^>]*>/i', function (array $matches): string {
            $anchor = $matches[0];

            if (! preg_match('/\btarget\s*=\s*(["\']?)_blank\1/i', $anchor)) {
                return $anchor;
            }

            if (preg_match('/\brel\s*=\s*(["\'])(.*?)\1/is', $anchor, $relMatch)) {
                $tokens = preg_split('/\s+/', trim($relMatch[2])) ?: [];

                foreach (['noopener', 'noreferrer'] as $token) {
                    if (! in_array($token, $tokens, true)) {
                        $tokens[] = $token;
                    }
                }

                $rel = implode(' ', array_filter($tokens));

                return preg_replace('/\brel\s*=\s*(["\'])(.*?)\1/is', 'rel="'.$rel.'"', $anchor, 1);
            }

            return substr($anchor, 0, -1).' rel="noopener noreferrer">';
        }, $html) ?? $html;
    }
}
