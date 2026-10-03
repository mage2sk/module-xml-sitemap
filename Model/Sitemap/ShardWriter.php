<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Sitemap;

class ShardWriter
{
    private \XMLWriter $writer;
    private int $count = 0;
    private bool $open = false;
    private string $path;
    private int $bytes = 0;
    private array $options = [];
    private bool $gzip = false;

    public function __construct(
        private readonly UrlElementWriter $urlElementWriter
    ) {
    }

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
        $this->options = [
            'include_hreflang'            => (bool) ($options['include_hreflang'] ?? true),
            'include_video'               => (bool) ($options['include_video'] ?? true),
            'include_changefreq_priority' => (bool) ($options['include_changefreq_priority'] ?? false),
        ];
        $this->gzip  = (bool) ($options['gzip'] ?? false);
        $this->bytes = 0;
        $this->urlElementWriter->writeUrlsetStart($this->writer, $this->options);
        $this->open = true;
        $this->count = 0;
    }

    public function writeUrl(array $url): void
    {
        if (!$this->open) {
            throw new \RuntimeException('Shard writer not opened.');
        }
        if ($this->urlElementWriter->write($this->writer, $url, $this->options)) {
            $this->count++;
        }
    }

    public function close(): string
    {
        if (!$this->open) {
            return $this->path;
        }
        $this->writer->endElement();
        $this->writer->endDocument();
        $this->bytes += (int) $this->writer->flush();
        unset($this->writer);
        $this->open = false;
        if ($this->gzip) {
            $this->path = $this->compress($this->path);
        }
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
        if ($this->open) {
            $this->bytes += (int) $this->writer->flush();
        }

        return $this->bytes;
    }

    private function compress(string $path): string
    {
        $target = $path . '.gz';
        $in = fopen($path, 'rb');
        if ($in === false) {
            throw new \RuntimeException('Cannot read sitemap shard for compression: ' . $path);
        }
        $out = gzopen($target, 'wb9');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException('Cannot open compressed sitemap shard for writing: ' . $target);
        }
        try {
            while (!feof($in)) {
                $chunk = fread($in, 1048576);
                if ($chunk === false) {
                    throw new \RuntimeException('Cannot read sitemap shard for compression: ' . $path);
                }
                if ($chunk !== '' && gzwrite($out, $chunk) === false) {
                    throw new \RuntimeException('Cannot write compressed sitemap shard: ' . $target);
                }
            }
        } finally {
            fclose($in);
            gzclose($out);
        }
        unlink($path);
        return $target;
    }
}
