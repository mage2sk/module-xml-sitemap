<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Helper;

class PathResolver
{
    public const DEFAULT_OUTPUT_PATH = 'xmlsitemap/{store_code}/';

    public const DEFAULT_PROFILE_PATH = '/';

    public const DEFAULT_FILENAME = 'sitemap.xml';

    public const STORE_CODE_PLACEHOLDER = '{store_code}';

    private const FILENAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]*(?:\.[A-Za-z0-9_-]+)*\.xml$/';

    private const SEGMENT_PATTERN = '/^[A-Za-z0-9._-]+$/';

    private const MAX_FILENAME_LENGTH = 128;

    private const MAX_PATH_LENGTH = 255;

    public function resolveRelativeDir(string $outputPath, string $storeCode): string
    {
        $path = trim($outputPath);
        if ($path === '') {
            $path = self::DEFAULT_OUTPUT_PATH;
        }
        $path = strtr($path, [self::STORE_CODE_PLACEHOLDER => $storeCode]);
        $path = trim($path);

        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..' || preg_match(self::SEGMENT_PATTERN, $segment) !== 1) {
                return 'xmlsitemap/' . $storeCode;
            }
            $segments[] = $segment;
        }
        return implode('/', $segments);
    }

    public function isValidOutputPath(string $path): bool
    {
        $path = trim($path);
        if ($path === '' || strlen($path) > self::MAX_PATH_LENGTH || str_contains($path, '\\')) {
            return false;
        }
        $path = str_replace(self::STORE_CODE_PLACEHOLDER, 'store', $path);
        foreach (explode('/', $path) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..' || preg_match(self::SEGMENT_PATTERN, $segment) !== 1) {
                return false;
            }
        }
        return true;
    }

    public function isValidFilename(string $filename): bool
    {
        return strlen($filename) <= self::MAX_FILENAME_LENGTH
            && preg_match(self::FILENAME_PATTERN, $filename) === 1;
    }

    public function resolveFilename(?string $profileFilename, string $fallback = self::DEFAULT_FILENAME): string
    {
        $name = trim((string) $profileFilename);
        if ($name !== '' && $this->isValidFilename($name)) {
            return $name;
        }
        $fallback = trim($fallback);
        return $this->isValidFilename($fallback) ? $fallback : self::DEFAULT_FILENAME;
    }

    public function getFileBase(string $filename): string
    {
        $base = preg_replace('/\.xml$/', '', $filename);
        return is_string($base) && $base !== '' ? $base : 'sitemap';
    }

    public function buildRelativeFile(string $relativeDir, string $filename): string
    {
        $dir = trim($relativeDir, '/');
        return $dir === '' ? $filename : $dir . '/' . $filename;
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
