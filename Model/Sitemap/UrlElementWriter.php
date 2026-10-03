<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Sitemap;

class UrlElementWriter
{
    public const NS_SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    public const NS_IMAGE   = 'http://www.google.com/schemas/sitemap-image/1.1';
    public const NS_XHTML   = 'http://www.w3.org/1999/xhtml';
    public const NS_VIDEO   = 'http://www.google.com/schemas/sitemap-video/1.1';

    private const CHANGEFREQ_VALUES = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];

    public function writeUrlsetStart(\XMLWriter $w, array $options): void
    {
        $w->startElement('urlset');
        $w->writeAttribute('xmlns', self::NS_SITEMAP);
        $w->writeAttribute('xmlns:image', self::NS_IMAGE);
        if (!empty($options['include_hreflang'])) {
            $w->writeAttribute('xmlns:xhtml', self::NS_XHTML);
        }
        if (!empty($options['include_video'])) {
            $w->writeAttribute('xmlns:video', self::NS_VIDEO);
        }
    }

    public function write(\XMLWriter $w, array $url, array $options): bool
    {
        $loc = (string) ($url['loc'] ?? '');
        if ($loc === '') {
            return false;
        }

        $w->startElement('url');
        $w->writeElement('loc', $loc);
        if (!empty($url['lastmod'])) {
            $w->writeElement('lastmod', (string) $url['lastmod']);
        }

        if (!empty($options['include_changefreq_priority'])) {
            $changefreq = strtolower(trim((string) ($url['changefreq'] ?? '')));
            if (in_array($changefreq, self::CHANGEFREQ_VALUES, true)) {
                $w->writeElement('changefreq', $changefreq);
            }
            if (isset($url['priority']) && is_numeric($url['priority'])) {
                $priority = (float) $url['priority'];
                if ($priority >= 0.0 && $priority <= 1.0) {
                    $w->writeElement('priority', number_format($priority, 1, '.', ''));
                }
            }
        }

        if (!empty($url['images']) && is_array($url['images'])) {
            foreach ($url['images'] as $img) {
                if (!is_array($img) || empty($img['loc'])) {
                    continue;
                }
                $w->startElement('image:image');
                $w->writeElement('image:loc', (string) $img['loc']);
                if (!empty($img['caption'])) {
                    $w->writeElement('image:caption', (string) $img['caption']);
                }
                if (!empty($img['title'])) {
                    $w->writeElement('image:title', (string) $img['title']);
                }
                $w->endElement();
            }
        }

        if (!empty($options['include_hreflang']) && !empty($url['hreflang']) && is_array($url['hreflang'])) {
            foreach ($url['hreflang'] as $alt) {
                if (!is_array($alt) || empty($alt['locale']) || empty($alt['url'])) {
                    continue;
                }
                $w->startElement('xhtml:link');
                $w->writeAttribute('rel', 'alternate');
                $w->writeAttribute('hreflang', (string) $alt['locale']);
                $w->writeAttribute('href', (string) $alt['url']);
                $w->endElement();
            }
        }

        if (!empty($options['include_video']) && !empty($url['video']) && is_array($url['video'])) {
            foreach ($url['video'] as $video) {
                $this->writeVideo($w, $video);
            }
        }

        $w->endElement();
        return true;
    }

    private function writeVideo(\XMLWriter $w, mixed $video): void
    {
        if (!is_array($video)) {
            return;
        }
        $contentLoc   = trim((string) ($video['content_loc'] ?? ''));
        $playerLoc    = trim((string) ($video['player_loc'] ?? ''));
        $thumbnailLoc = trim((string) ($video['thumbnail_loc'] ?? ''));
        $title        = trim((string) ($video['title'] ?? ''));
        $description  = trim((string) ($video['description'] ?? ''));
        if ($description === '') {
            $description = $title;
        }
        if (($contentLoc === '' && $playerLoc === '') || $thumbnailLoc === '' || $title === '') {
            return;
        }

        $w->startElement('video:video');
        $w->writeElement('video:thumbnail_loc', $thumbnailLoc);
        $w->writeElement('video:title', $title);
        $w->writeElement('video:description', $description);
        if ($contentLoc !== '') {
            $w->writeElement('video:content_loc', $contentLoc);
        }
        if ($playerLoc !== '') {
            $w->writeElement('video:player_loc', $playerLoc);
        }
        $w->endElement();
    }
}
