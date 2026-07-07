<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Helper;

class PathResolver
{
    public function resolveRelativeDir(string $outputPath, string $storeCode): string
    {
        $path = trim($outputPath);
        if ($path === '') {
            return '';
        }
        $path = strtr($path, ['{store_code}' => $storeCode]);
        $path = trim($path);

        $path = (string) preg_replace('#/+#', '/', $path);
        $path = trim($path, '/');
        return $path;
    }

    public function buildSitemapUrl(string $baseUrl, string $relativeDir, string $filename = 'sitemap_index.xml'): string
    {
        $base = rtrim($baseUrl, '/');
        $file = ltrim($filename, '/');
        if ($relativeDir === '') {
            return $this->normaliseUrl($base . '/' . $file);
        }
        return $this->normaliseUrl($base . '/' . trim($relativeDir, '/') . '/' . $file);
    }

    public function normaliseUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $schemeSeparator = '://';
        $pos = strpos($url, $schemeSeparator);
        if ($pos === false) {
            return (string) preg_replace('#/+#', '/', $url);
        }
        $scheme = substr($url, 0, $pos + strlen($schemeSeparator));
        $rest   = substr($url, $pos + strlen($schemeSeparator));
        $rest   = (string) preg_replace('#/+#', '/', $rest);
        return $scheme . $rest;
    }
}
