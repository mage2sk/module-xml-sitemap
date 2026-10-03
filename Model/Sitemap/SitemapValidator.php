<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Sitemap;

use Magento\Store\Model\StoreManagerInterface;

class SitemapValidator
{
    private const MAX_URLS      = 50_000;
    private const MAX_FILE_SIZE = 52_428_800;

    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function validate(string $filePath): array
    {
        $errors = [];

        if (!is_file($filePath) || !is_readable($filePath)) {
            return ['Sitemap file does not exist or is not readable: ' . $filePath];
        }

        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            $errors[] = 'Unable to determine file size for: ' . $filePath;
        } elseif ($fileSize > self::MAX_FILE_SIZE) {
            $errors[] = sprintf(
                'Sitemap file exceeds 50 MB limit: %s (%.2f MB)',
                basename($filePath),
                $fileSize / 1_048_576
            );
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $xml = new \DOMDocument();
        $loaded = $xml->load($filePath);

        $libxmlErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        if (!$loaded) {
            $errors[] = 'Sitemap file is not valid XML: ' . $filePath;
            foreach ($libxmlErrors as $libxmlError) {
                $errors[] = sprintf(
                    'XML error (line %d, col %d): %s',
                    $libxmlError->line,
                    $libxmlError->column,
                    trim($libxmlError->message)
                );
            }

            return $errors;
        }

        foreach ($libxmlErrors as $libxmlError) {
            if ($libxmlError->level >= LIBXML_ERR_ERROR) {
                $errors[] = sprintf(
                    'XML error (line %d, col %d): %s',
                    $libxmlError->line,
                    $libxmlError->column,
                    trim($libxmlError->message)
                );
            }
        }

        $xpath    = new \DOMXPath($xml);
        $xpath->registerNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $locNodes = $xpath->query('//sm:url/sm:loc');

        if ($locNodes !== false && $locNodes->length >= self::MAX_URLS) {
            $errors[] = sprintf(
                'Sitemap contains %d URLs, which exceeds the 50,000 URL limit.',
                $locNodes->length
            );
        }

        $baseUrls = $this->collectBaseUrls();

        if ($locNodes !== false && $baseUrls !== []) {
            foreach ($locNodes as $node) {
                $loc = trim((string) $node->textContent);
                if ($loc === '') {
                    $errors[] = 'Empty <loc> element found.';
                    continue;
                }

                $matchesBase = false;
                foreach ($baseUrls as $base) {
                    if (str_starts_with($loc, $base)) {
                        $matchesBase = true;
                        break;
                    }
                }
                if (!$matchesBase) {
                    $errors[] = sprintf(
                        'URL does not match any configured base URL: %s',
                        $loc
                    );
                }
            }
        }

        return $errors;
    }

    private function collectBaseUrls(): array
    {
        $urls = [];
        try {
            foreach ($this->storeManager->getStores() as $store) {
                $base = rtrim((string) $store->getBaseUrl(), '/');
                if ($base !== '') {
                    $urls[$base] = $base;
                }
            }
        } catch (\Throwable) {
        }

        return array_values($urls);
    }
}
