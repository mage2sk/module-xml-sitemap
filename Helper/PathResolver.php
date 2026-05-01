<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Helper;

/**
 * Single-source-of-truth resolver for the profile's output path and the
 * matching public URL.
 *
 * Why centralised: the same logic was previously duplicated in
 * {@see \Panth\XmlSitemap\Model\Sitemap\Builder},
 * {@see \Panth\XmlSitemap\Block\Adminhtml\Profile\Edit\ViewSitemapButton}
 * and {@see \Panth\XmlSitemap\Ui\Component\Listing\Column\ProfileActions}.
 * Two of those forks left subtle gaps:
 *
 *   - Empty `output_path` defaulted to `sitemap/<store_code>` rather than
 *     writing the index to pub/ root, so a merchant who wanted
 *     `/sitemap_index.xml` got `/sitemap/<code>/sitemap_index.xml`.
 *   - When the path normalisation produced an empty relative dir (the
 *     admin set `/`, or `{store_code}/` resolved to nothing), the URL
 *     concatenation produced a `//sitemap_index.xml` double-slash.
 *
 * This helper resolves both:
 *   - Empty `output_path` → relative dir is `''` (root). The writer
 *     drops files directly into pub/, the URL is `{baseUrl}/sitemap_index.xml`.
 *   - Non-empty path with stray slashes → trimmed and joined exactly
 *     once, so the URL never carries `//`.
 */
class PathResolver
{
    /**
     * Normalise the merchant-authored output_path string into a clean
     * relative directory below pub/. Returns `''` when the merchant
     * wants the sitemap at the document root.
     */
    public function resolveRelativeDir(string $outputPath, string $storeCode): string
    {
        $path = trim($outputPath);
        if ($path === '') {
            // Empty admin field => sitemap at pub/ root (clean URL).
            return '';
        }
        $path = strtr($path, ['{store_code}' => $storeCode]);
        $path = trim($path);
        // Collapse any leading/trailing slashes (the writer never wants
        // them) and any redundant interior slashes from a stray
        // `{store_code}//` substitution.
        $path = (string) preg_replace('#/+#', '/', $path);
        $path = trim($path, '/');
        return $path;
    }

    /**
     * Build the public URL for the sitemap index given a base URL and a
     * relative directory previously produced by {@see resolveRelativeDir()}.
     *
     * Always emits exactly one slash between the base URL and the file
     * name, regardless of whether the relative directory is empty.
     *
     * Defence-in-depth: the result is run through {@see normaliseUrl()}
     * so any double-slash that ever leaks through (a relative dir that
     * starts with `/`, a base URL that contains `//foo` for whatever
     * legacy reason, etc.) is collapsed before the URL is emitted into
     * a sitemap_index.xml.
     */
    public function buildSitemapUrl(string $baseUrl, string $relativeDir, string $filename = 'sitemap_index.xml'): string
    {
        $base = rtrim($baseUrl, '/');
        $file = ltrim($filename, '/');
        if ($relativeDir === '') {
            return $this->normaliseUrl($base . '/' . $file);
        }
        return $this->normaliseUrl($base . '/' . trim($relativeDir, '/') . '/' . $file);
    }

    /**
     * Collapse `//` that appears anywhere AFTER the URL scheme down to a
     * single `/`. Idempotent + protocol-safe (`https://host` is left
     * alone). Use this any time a URL is hand-built by string concat.
     *
     * Example:
     *
     *     normaliseUrl('https://radheeimitation.com//sitemap-products-1.xml')
     *         === 'https://radheeimitation.com/sitemap-products-1.xml'
     */
    public function normaliseUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        // Split on the scheme delimiter so the `https://` part is kept
        // exactly as the caller passed it (we only normalise the path).
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
