<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Helper;

class PathResolver
{
    public const DEFAULT_OUTPUT_PATH = 'xmlsitemap/{store_code}/';

    public function resolveRelativeDir(string $outputPath, string $storeCode): string
    {
        $path = trim($outputPath);
        if ($path === '') {
            $path = self::DEFAULT_OUTPUT_PATH;
        }
        $path = strtr($path, ['{store_code}' => $storeCode]);
        $path = trim($path);

        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..' || preg_match('/^[A-Za-z0-9._-]+$/', $segment) !== 1) {
                return 'xmlsitemap/' . $storeCode;
            }
            $segments[] = $segment;
        }
        return implode('/', $segments);
    }

    public function buildSitemapUrl(string $baseUrl, string $relativeDir, string $filename = 'sitemap.xml'): string
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
