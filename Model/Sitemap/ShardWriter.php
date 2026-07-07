<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Sitemap;

class ShardWriter
{
    private \XMLWriter $writer;
    private int $count = 0;
    private bool $open = false;
    private string $path;

    public function open(string $path, ?string $xslHref = null, array $options = []): void
    {
        $this->path = $path;
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create sitemap directory: ' . $dir);
        }
        $this->writer = new \XMLWriter();
        if (!$this->writer->openUri($path)) {
            throw new \RuntimeException('Cannot open sitemap shard for writing: ' . $path);
        }
        $this->writer->setIndent(false);
        $this->writer->startDocument('1.0', 'UTF-8');
        if ($xslHref !== null && $xslHref !== '') {
            $this->writer->writePi(
                'xml-stylesheet',
                'type="text/xsl" href="' . htmlspecialchars($xslHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"'
            );
        }
        $includeHreflang = (bool) ($options['include_hreflang'] ?? true);
        $includeVideo    = (bool) ($options['include_video'] ?? true);
        $this->writer->startElement('urlset');
        $this->writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $this->writer->writeAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');
        if ($includeHreflang) {
            $this->writer->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');
        }
        if ($includeVideo) {
            $this->writer->writeAttribute('xmlns:video', 'http://www.google.com/schemas/sitemap-video/1.1');
        }
        $this->open = true;
        $this->count = 0;
    }

    public function writeUrl(array $url): void
    {
        if (!$this->open) {
            throw new \RuntimeException('Shard writer not opened.');
        }
        $loc = (string) ($url['loc'] ?? '');
        if ($loc === '') {
            return;
        }
        $w = $this->writer;
        $w->startElement('url');
        $w->writeElement('loc', $loc);
        if (!empty($url['lastmod'])) {
            $w->writeElement('lastmod', (string) $url['lastmod']);
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
        if (!empty($url['hreflang']) && is_array($url['hreflang'])) {
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
        if (!empty($url['video']) && is_array($url['video'])) {
            foreach ($url['video'] as $video) {
                if (!is_array($video) || empty($video['content_loc'])) {
                    continue;
                }
                $w->startElement('video:video');
                $w->writeElement('video:content_loc', (string) $video['content_loc']);
                if (!empty($video['title'])) {
                    $w->writeElement('video:title', (string) $video['title']);
                }
                if (!empty($video['description'])) {
                    $w->writeElement('video:description', (string) $video['description']);
                }
                if (!empty($video['thumbnail_loc'])) {
                    $w->writeElement('video:thumbnail_loc', (string) $video['thumbnail_loc']);
                }
                $w->endElement();
            }
        }
        $w->endElement();
        $this->count++;
    }

    public function close(): string
    {
        if (!$this->open) {
            return $this->path;
        }
        $this->writer->endElement();
        $this->writer->endDocument();
        $this->writer->flush();
        unset($this->writer);
        $this->open = false;
        return $this->path;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getFileSize(): int
    {
        if (!$this->open) {
            return file_exists($this->path) ? (int) filesize($this->path) : 0;
        }

        $this->writer->flush();
        return file_exists($this->path) ? (int) filesize($this->path) : 0;
    }
}
