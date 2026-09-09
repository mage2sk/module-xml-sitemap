<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Sitemap;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\HTTP\ClientInterface;
use Panth\XmlSitemap\Api\BuilderInterface;
use Panth\XmlSitemap\Api\ContributorInterface;
use Panth\XmlSitemap\Helper\Config;
use Panth\XmlSitemap\Helper\PathResolver;
use Psr\Log\LoggerInterface;

class Builder implements BuilderInterface
{
    private const KNOWN_INDEX_FILENAMES = ['sitemap.xml', 'sitemap_index.xml'];

    private const XSL_FILENAME = 'sitemap-style.xsl';

    private const MAX_FILE_SIZE_BYTES = 50 * 1024 * 1024;

    private const ENTITY_PREFIX_MAP = [
        'product'      => 'sitemap-products',
        'category'     => 'sitemap-categories',
        'cms_page'     => 'sitemap-cms',
        'custom'       => 'sitemap-custom',

        'testimonial'  => 'sitemap-testimonials',
        'faq'          => 'sitemap-faqs',
        'dynamic_form' => 'sitemap-dynamic-forms',
    ];

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Filesystem $filesystem,
        private readonly ShardWriterFactory $shardFactory,
        private readonly IndexWriter $indexWriter,
        private readonly DeltaTracker $deltaTracker,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly ClientInterface $httpClient,
        private readonly XslStylesheet $xslStylesheet,
        private readonly ResourceConnection $resourceConnection,
        private readonly PathResolver $pathResolver,
        private readonly array $contributors = []
    ) {
    }

    public function build(int $storeId): iterable
    {
        $store     = $this->storeManager->getStore($storeId);
        $storeCode = (string) $store->getCode();
        $shardSize = $this->config->getSitemapShardSize($storeId);
        $baseUrl   = rtrim((string) $store->getBaseUrl(), '/');

        $pub = $this->filesystem->getDirectoryWrite(DirectoryList::PUB);

        $relDir = 'xmlsitemap/' . $storeCode;
        $pub->create($relDir);
        $absDir = $pub->getAbsolutePath($relDir);

        foreach (glob(rtrim($absDir, '/') . '/sitemap-*.xml') ?: [] as $old) {
            if (file_exists($old)) {
                try {
                    unlink($old);
                } catch (\Throwable) {
                }
            }
        }
        $indexFilename = $this->config->getSitemapIndexFilename($storeId);
        $indexFile = rtrim($absDir, '/') . '/' . $indexFilename;
        $this->removeStaleIndex($absDir, $indexFilename);

        $xslEnabled = $this->config->isSitemapXslEnabled($storeId);
        $xslHref    = $xslEnabled ? self::XSL_FILENAME : null;

        if ($xslEnabled) {
            $this->writeXslStylesheet($absDir);
        }

        $shards = [];
        $files  = [];

        $shardIdx = 0;
        $urlCount = 0;
        $shard    = null;
        $now      = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');

        $openShard = function () use (&$shard, &$shardIdx, &$urlCount, $absDir, $xslHref): void {
            $shardIdx++;
            $path = rtrim($absDir, '/') . '/sitemap-' . $shardIdx . '.xml';
            $shard = $this->shardFactory->create();
            $shard->open($path, $xslHref);
            $urlCount = 0;
        };

        $pathResolver = $this->pathResolver;
        $relDirForLegacy = 'xmlsitemap/' . trim($storeCode, '/');
        $closeShard = function () use (&$shard, &$shards, &$files, $baseUrl, $relDirForLegacy, $now, $pathResolver): void {
            if ($shard === null) {
                return;
            }
            $path = $shard->close();
            $files[] = $path;
            $filename = basename($path);
            $shards[] = [
                'loc'     => $pathResolver->buildSitemapUrl($baseUrl, $relDirForLegacy, $filename),
                'lastmod' => $now,
            ];
            $shard = null;
        };

        try {
            foreach ($this->contributors as $contributor) {
                if (!$contributor instanceof ContributorInterface) {
                    continue;
                }
                try {
                    foreach ($contributor->getUrls($storeId) as $url) {
                        if (!is_array($url) || empty($url['loc'])) {
                            continue;
                        }

                        $url['loc'] = $this->pathResolver->normaliseUrl((string) $url['loc']);
                        if ($shard === null) {
                            $openShard();
                        }
                        $shard->writeUrl($url);
                        $urlCount++;
                        if ($urlCount >= $shardSize) {
                            $closeShard();
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->error(
                        '[PanthSEO] sitemap contributor "' . $contributor->getCode() . '" failed: ' . $e->getMessage()
                    );
                }
            }
            $closeShard();

            if (!empty($shards)) {
                $this->indexWriter->write($indexFile, $shards, $xslHref);
                $files[] = $indexFile;
            }

            $this->deltaTracker->mark($storeId, $now);

            if (!empty($shards)) {
                $sitemapUrl = $shards[0]['loc'] ?? '';
                if (count($shards) > 1) {
                    $sitemapUrl = $this->pathResolver->buildSitemapUrl($baseUrl, $relDirForLegacy);
                }
                $this->pingSearchEngines($storeId, $sitemapUrl);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[PanthSEO] sitemap build failed: ' . $e->getMessage());
            if ($shard !== null) {
                try {
                    $shard->close();
                } catch (\Throwable) {
                }
            }
            throw $e;
        }

        return $files;
    }

    public function buildForStore(int $storeId): string
    {
        $store   = $this->storeManager->getStore($storeId);
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');

        $xslEnabled = $this->config->isSitemapXslEnabled($storeId);

        $profile       = $this->loadActiveProfileForStore($storeId);
        $allowedBuckets = $this->resolveEntityBuckets(
            (string) ($profile['entity_types'] ?? '')
        );
        $contributorBucketMap = [
            'product'      => 'product',
            'category'     => 'category',
            'cms_page'     => 'cms',
            'landing_page' => 'product',
            'blog'         => 'cms',
            'testimonial'  => 'testimonial',
            'faq'          => 'faq',
            'dynamic_form' => 'dynamic_form',
        ];
        $profileConfig = $profile ? [
            'exclude_out_of_stock'     => (bool) ($profile['exclude_out_of_stock'] ?? false),
            'exclude_noindex'          => (bool) ($profile['exclude_noindex'] ?? false),
            'excluded_cms_identifiers' => (string) ($profile['excluded_cms_identifiers'] ?? ''),
            'include_images'           => (bool) ($profile['include_images'] ?? true),
            'include_hreflang_tags'    => (bool) ($profile['include_hreflang_tags'] ?? true),
            'include_video_sitemap'    => (bool) ($profile['include_video_sitemap'] ?? true),
            'priority_homepage'        => isset($profile['priority_homepage'])
                ? (float) $profile['priority_homepage']
                : null,
        ] : [];
        $includeHreflang = (bool) ($profileConfig['include_hreflang_tags'] ?? true);
        $includeVideo    = (bool) ($profileConfig['include_video_sitemap'] ?? true);

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');

        if ($xslEnabled) {
            $xslUrl = $this->pathResolver->buildSitemapUrl(
                $baseUrl,
                'xmlsitemap/' . trim((string) $store->getCode(), '/'),
                self::XSL_FILENAME
            );
            $xml->writePi(
                'xml-stylesheet',
                'type="text/xsl" href="' . htmlspecialchars($xslUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"'
            );
        }

        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->writeAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');
        if ($includeHreflang) {
            $xml->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');
        }
        if ($includeVideo) {
            $xml->writeAttribute('xmlns:video', 'http://www.google.com/schemas/sitemap-video/1.1');
        }

        $seenLocs = [];

        foreach ($this->contributors as $contributor) {
            if (!$contributor instanceof ContributorInterface) {
                continue;
            }

            if ($allowedBuckets !== null) {
                $bucket = $contributorBucketMap[$contributor->getCode()] ?? null;
                if ($bucket !== null && !isset($allowedBuckets[$bucket])) {
                    continue;
                }
            }

            try {
                foreach ($contributor->getUrls($storeId, $profileConfig) as $url) {
                    if (!is_array($url) || empty($url['loc'])) {
                        continue;
                    }
                    $loc = $this->pathResolver->normaliseUrl((string) $url['loc']);

                    if ($loc === '' || isset($seenLocs[$loc])) {
                        continue;
                    }
                    $seenLocs[$loc] = true;
                    $xml->startElement('url');
                    $xml->writeElement('loc', $loc);
                    if (!empty($url['lastmod'])) {
                        $xml->writeElement('lastmod', (string) $url['lastmod']);
                    }

                    if (!empty($url['images']) && is_array($url['images'])) {
                        foreach ($url['images'] as $img) {
                            if (!is_array($img) || empty($img['loc'])) {
                                continue;
                            }
                            $xml->startElement('image:image');
                            $xml->writeElement('image:loc', (string) $img['loc']);
                            if (!empty($img['caption'])) {
                                $xml->writeElement('image:caption', (string) $img['caption']);
                            }
                            if (!empty($img['title'])) {
                                $xml->writeElement('image:title', (string) $img['title']);
                            }
                            $xml->endElement();
                        }
                    }
                    if (!empty($url['hreflang']) && is_array($url['hreflang'])) {
                        foreach ($url['hreflang'] as $alt) {
                            if (!is_array($alt) || empty($alt['locale']) || empty($alt['url'])) {
                                continue;
                            }
                            $xml->startElement('xhtml:link');
                            $xml->writeAttribute('rel', 'alternate');
                            $xml->writeAttribute('hreflang', (string) $alt['locale']);
                            $xml->writeAttribute('href', (string) $alt['url']);
                            $xml->endElement();
                        }
                    }
                    if (!empty($url['video']) && is_array($url['video'])) {
                        foreach ($url['video'] as $video) {
                            if (!is_array($video) || empty($video['content_loc'])) {
                                continue;
                            }
                            $xml->startElement('video:video');
                            $xml->writeElement('video:content_loc', (string) $video['content_loc']);
                            if (!empty($video['title'])) {
                                $xml->writeElement('video:title', (string) $video['title']);
                            }
                            if (!empty($video['description'])) {
                                $xml->writeElement('video:description', (string) $video['description']);
                            }
                            if (!empty($video['thumbnail_loc'])) {
                                $xml->writeElement('video:thumbnail_loc', (string) $video['thumbnail_loc']);
                            }
                            $xml->endElement();
                        }
                    }
                    $xml->endElement();
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    '[PanthSEO] sitemap contributor "' . $contributor->getCode() . '" failed: ' . $e->getMessage()
                );
            }
        }

        if ($allowedBuckets === null || isset($allowedBuckets['custom'])) {
            $customLinks = $profile ? $this->resolveCustomLinks($profile, $baseUrl) : [];
            foreach ($customLinks as $link) {
                $loc = (string) ($link['loc'] ?? '');
                if ($loc === '' || isset($seenLocs[$loc])) {
                    continue;
                }
                $seenLocs[$loc] = true;
                $xml->startElement('url');
                $xml->writeElement('loc', $loc);

                $xml->endElement();
            }
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function loadActiveProfileForStore(int $storeId): ?array
    {
        try {
            $conn  = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('panth_seo_sitemap_profile');
            if (!$conn->isTableExists($table)) {
                return null;
            }

            $select = $conn->select()
                ->from($table)
                ->where('is_active = ?', 1)
                ->where('store_id IN (?)', [$storeId, 0])
                ->order(new \Zend_Db_Expr('store_id = ' . $storeId . ' DESC'))
                ->limit(1);

            $row = $conn->fetchRow($select);
            return is_array($row) && !empty($row) ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function buildFromProfile(array $profile): array
    {
        $startTime = microtime(true);

        $storeId   = (int) ($profile['store_id'] ?? 0);
        $store     = $this->storeManager->getStore($storeId);
        $storeCode = (string) $store->getCode();
        $baseUrl   = rtrim((string) $store->getBaseUrl(), '/');

        $maxUrlsPerFile = (int) ($profile['max_urls_per_file'] ?? 50000);
        if ($maxUrlsPerFile <= 0 || $maxUrlsPerFile > 50000) {
            $maxUrlsPerFile = 50000;
        }

        $profileConfig = [
            'exclude_out_of_stock'     => (bool) ($profile['exclude_out_of_stock'] ?? false),
            'exclude_noindex'          => (bool) ($profile['exclude_noindex'] ?? false),
            'excluded_cms_identifiers' => (string) ($profile['excluded_cms_identifiers'] ?? ''),
            'include_images'           => (bool) ($profile['include_images'] ?? true),
            'include_hreflang_tags'    => (bool) ($profile['include_hreflang_tags'] ?? true),
            'include_video_sitemap'    => (bool) ($profile['include_video_sitemap'] ?? true),
            'priority_homepage'        => isset($profile['priority_homepage'])
                ? (float) $profile['priority_homepage']
                : null,
        ];

        $entitySettings = $this->resolveEntitySettings($profile);

        $outputPath = (string) ($profile['output_path'] ?? '');
        $profileDir = $this->pathResolver->resolveRelativeDir($outputPath, $storeCode);
        $pub = $this->filesystem->getDirectoryWrite(DirectoryList::PUB);
        if ($profileDir !== '') {
            $pub->create($profileDir);
            $absDir = $pub->getAbsolutePath($profileDir);
        } else {
            $absDir = $pub->getAbsolutePath();
        }

        foreach (glob(rtrim($absDir, '/') . '/sitemap-*.xml') ?: [] as $old) {
            if (file_exists($old)) {
                try {
                    unlink($old);
                } catch (\Throwable) {
                }
            }
        }
        $indexFilename = $this->config->getSitemapIndexFilename($storeId);
        $indexFile = rtrim($absDir, '/') . '/' . $indexFilename;
        $this->removeStaleIndex($absDir, $indexFilename);

        $xslEnabled = $this->config->isSitemapXslEnabled($storeId);
        $xslHref    = $xslEnabled ? self::XSL_FILENAME : null;

        if ($xslEnabled) {
            $this->writeXslStylesheet($absDir);
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');

        $indexEntries  = [];
        $allFiles      = [];
        $totalUrlCount = 0;

        $contributorEntityMap = [
            'product'      => 'product',
            'category'     => 'category',
            'cms_page'     => 'cms_page',
            'testimonial'  => 'testimonial',
            'faq'          => 'faq',
            'dynamic_form' => 'dynamic_form',
        ];

        $allowedBuckets = $this->resolveEntityBuckets((string) ($profile['entity_types'] ?? ''));
        $contributorBucketMap = [
            'product'       => 'product',
            'category'      => 'category',
            'cms_page'      => 'cms',
            'landing_page'  => 'product',
            'blog'          => 'cms',
            'testimonial'   => 'testimonial',
            'faq'           => 'faq',
        ];

        foreach ($this->contributors as $contributor) {
            if (!$contributor instanceof ContributorInterface) {
                continue;
            }

            $code       = $contributor->getCode();
            $entityType = $contributorEntityMap[$code] ?? $code;
            $prefix     = self::ENTITY_PREFIX_MAP[$entityType] ?? ('sitemap-' . $entityType);

            if ($allowedBuckets !== null) {
                $bucket = $contributorBucketMap[$code] ?? null;
                if ($bucket !== null && !isset($allowedBuckets[$bucket])) {
                    continue;
                }
            }

            $contributorConfig = $profileConfig;
            if (isset($entitySettings[$entityType])) {
                $contributorConfig = array_merge($contributorConfig, $entitySettings[$entityType]);
            }

            try {
                $result = $this->writeEntityShards(
                    $contributor,
                    $storeId,
                    $contributorConfig,
                    $absDir,
                    $prefix,
                    $maxUrlsPerFile,
                    $xslHref,
                    $baseUrl,
                    $profileDir,
                    $now
                );

                $indexEntries  = array_merge($indexEntries, $result['shards']);
                $allFiles      = array_merge($allFiles, $result['files']);
                $totalUrlCount += $result['url_count'];
            } catch (\Throwable $e) {
                $this->logger->error(
                    '[PanthSEO] sitemap profile contributor "' . $code . '" failed: ' . $e->getMessage()
                );
            }
        }

        $customLinks = ($allowedBuckets === null || isset($allowedBuckets['custom']))
            ? $this->resolveCustomLinks($profile, $baseUrl)
            : [];
        if (!empty($customLinks)) {
            $result = $this->writeCustomLinkShards(
                $customLinks,
                $absDir,
                $maxUrlsPerFile,
                $xslHref,
                $baseUrl,
                $profileDir,
                $now,
                $entitySettings['custom'] ?? []
            );
            $indexEntries  = array_merge($indexEntries, $result['shards']);
            $allFiles      = array_merge($allFiles, $result['files']);
            $totalUrlCount += $result['url_count'];
        }

        if (!empty($indexEntries)) {
            $this->indexWriter->write($indexFile, $indexEntries, $xslHref);
            $allFiles[] = $indexFile;
        }

        $this->deltaTracker->mark($storeId, $now);

        if (!empty($indexEntries)) {
            $sitemapUrl = $this->pathResolver->buildSitemapUrl($baseUrl, $profileDir);
            $this->pingSearchEngines($storeId, $sitemapUrl);
        }

        $generationTime = round(microtime(true) - $startTime, 2);

        return [
            'url_count'       => $totalUrlCount,
            'file_count'      => count($allFiles),
            'generation_time' => $generationTime,
            'files'           => $allFiles,
        ];
    }

    private function writeEntityShards(
        ContributorInterface $contributor,
        int $storeId,
        array $config,
        string $absDir,
        string $prefix,
        int $maxUrlsPerFile,
        ?string $xslHref,
        string $baseUrl,
        string $profileDir,
        string $now
    ): array {
        $shards   = [];
        $files    = [];
        $urlCount = 0;
        $shardIdx = 0;
        $shardUrlCount = 0;
        $shard    = null;

        $shardOptions = [
            'include_hreflang' => (bool) ($config['include_hreflang_tags'] ?? true),
            'include_video'    => (bool) ($config['include_video_sitemap'] ?? true),
        ];

        $openShard = function () use (&$shard, &$shardIdx, &$shardUrlCount, $absDir, $prefix, $xslHref, $shardOptions): void {
            $shardIdx++;
            $path = rtrim($absDir, '/') . '/' . $prefix . '-' . $shardIdx . '.xml';
            $shard = $this->shardFactory->create();
            $shard->open($path, $xslHref, $shardOptions);
            $shardUrlCount = 0;
        };

        $pathResolver = $this->pathResolver;
        $closeShard = function () use (&$shard, &$shards, &$files, $baseUrl, $profileDir, $now, $pathResolver): void {
            if ($shard === null) {
                return;
            }
            $path = $shard->close();
            $files[] = $path;
            $filename = basename($path);
            $shards[] = [
                'loc'     => $pathResolver->buildSitemapUrl($baseUrl, $profileDir, $filename),
                'lastmod' => $now,
            ];
            $shard = null;
        };

        foreach ($contributor->getUrls($storeId, $config) as $url) {
            if (!is_array($url) || empty($url['loc'])) {
                continue;
            }

            $url['loc'] = $this->pathResolver->normaliseUrl((string) $url['loc']);

            if (empty($url['lastmod'])) {
                $url['lastmod'] = $now;
            }

            if ($shard === null) {
                $openShard();
            }

            $shard->writeUrl($url);
            $shardUrlCount++;
            $urlCount++;

            if ($shardUrlCount >= $maxUrlsPerFile
                || $shard->getFileSize() >= self::MAX_FILE_SIZE_BYTES
            ) {
                $closeShard();
            }
        }

        $closeShard();

        return [
            'shards'    => $shards,
            'files'     => $files,
            'url_count' => $urlCount,
        ];
    }

    private function writeCustomLinkShards(
        array $links,
        string $absDir,
        int $maxUrlsPerFile,
        ?string $xslHref,
        string $baseUrl,
        string $profileDir,
        string $now,
        array $entitySettings
    ): array {
        $shards   = [];
        $files    = [];
        $urlCount = 0;
        $shardIdx = 0;
        $shardUrlCount = 0;
        $shard    = null;
        $prefix   = self::ENTITY_PREFIX_MAP['custom'];

        $defaultChangefreq = $entitySettings['changefreq'] ?? 'weekly';
        $defaultPriority   = isset($entitySettings['priority']) ? (float) $entitySettings['priority'] : 0.5;

        $customShardOptions = ['include_hreflang' => false, 'include_video' => false];

        $openShard = function () use (&$shard, &$shardIdx, &$shardUrlCount, $absDir, $prefix, $xslHref, $customShardOptions): void {
            $shardIdx++;
            $path = rtrim($absDir, '/') . '/' . $prefix . '-' . $shardIdx . '.xml';
            $shard = $this->shardFactory->create();
            $shard->open($path, $xslHref, $customShardOptions);
            $shardUrlCount = 0;
        };

        $pathResolver = $this->pathResolver;
        $closeShard = function () use (&$shard, &$shards, &$files, $baseUrl, $profileDir, $now, $pathResolver): void {
            if ($shard === null) {
                return;
            }
            $path = $shard->close();
            $files[] = $path;
            $filename = basename($path);
            $shards[] = [
                'loc'     => $pathResolver->buildSitemapUrl($baseUrl, $profileDir, $filename),
                'lastmod' => $now,
            ];
            $shard = null;
        };

        foreach ($links as $link) {
            if (empty($link['loc'])) {
                continue;
            }

            $url = [
                'loc'        => $this->pathResolver->normaliseUrl((string) $link['loc']),
                'lastmod'    => $now,
                'changefreq' => $link['changefreq'] ?? $defaultChangefreq,
                'priority'   => $link['priority'] ?? $defaultPriority,
            ];

            if ($shard === null) {
                $openShard();
            }

            $shard->writeUrl($url);
            $shardUrlCount++;
            $urlCount++;

            if ($shardUrlCount >= $maxUrlsPerFile
                || $shard->getFileSize() >= self::MAX_FILE_SIZE_BYTES
            ) {
                $closeShard();
            }
        }

        $closeShard();

        return [
            'shards'    => $shards,
            'files'     => $files,
            'url_count' => $urlCount,
        ];
    }

    private function resolveEntitySettings(array $profile): array
    {
        if (!empty($profile['entity_settings'])) {
            $decoded = is_string($profile['entity_settings'])
                ? json_decode($profile['entity_settings'], true)
                : $profile['entity_settings'];
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $settings = [];

        $types = [
            'product'  => ['product'],
            'category' => ['category'],
            'cms_page' => ['cms_page', 'cms'],
            'custom'   => ['custom'],
        ];
        foreach ($types as $type => $aliases) {
            $cf = null;
            $pr = null;
            foreach ($aliases as $alias) {
                $cf = $cf ?? ($profile[$alias . '_changefreq'] ?? null);
                $pr = $pr ?? ($profile[$alias . '_priority'] ?? null);

                $cf = $cf ?? ($profile['changefreq_' . $alias] ?? null);
                $pr = $pr ?? ($profile['priority_' . $alias] ?? null);
            }

            if ($cf !== null || $pr !== null) {
                $s = [];
                if ($cf !== null) {
                    $s['changefreq'] = (string) $cf;
                }
                if ($pr !== null) {
                    $s['priority'] = (float) $pr;
                }
                $settings[$type] = $s;
            }
        }

        return $settings;
    }

    private function resolveEntityBuckets(string $entityTypes): ?array
    {
        $trimmed = trim($entityTypes);
        if ($trimmed === '') {
            return null;
        }
        $parts = array_filter(array_map('trim', explode(',', $trimmed)));
        if (empty($parts)) {
            return null;
        }
        return array_fill_keys($parts, true);
    }

    private function resolveCustomLinks(array $profile, string $baseUrl): array
    {
        $raw = $profile['custom_links'] ?? '';
        if (empty($raw)) {
            return [];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                $lines = array_filter(array_map('trim', explode("\n", $raw)));
                $links = [];
                foreach ($lines as $line) {
                    if ($line === '') {
                        continue;
                    }
                    [$loc, $changefreq, $priority] = array_pad(
                        array_map('trim', explode(',', $line, 3)),
                        3,
                        null
                    );
                    if ($loc === null || $loc === '') {
                        continue;
                    }
                    $url = str_starts_with($loc, 'http')
                        ? $this->pathResolver->normaliseUrl($loc)
                        : $this->pathResolver->normaliseUrl($baseUrl . '/' . ltrim($loc, '/'));
                    $entry = ['loc' => $url];
                    if ($changefreq !== null && $changefreq !== '') {
                        $entry['changefreq'] = $changefreq;
                    }
                    if ($priority !== null && $priority !== '' && is_numeric($priority)) {
                        $entry['priority'] = (float) $priority;
                    }
                    $links[] = $entry;
                }
                return $links;
            }
        }

        if (is_array($raw)) {
            $links = [];
            foreach ($raw as $item) {
                if (is_string($item)) {
                    $url = str_starts_with($item, 'http')
                        ? $this->pathResolver->normaliseUrl($item)
                        : $this->pathResolver->normaliseUrl($baseUrl . '/' . ltrim($item, '/'));
                    $links[] = ['loc' => $url];
                } elseif (is_array($item) && !empty($item['loc'])) {
                    $url = str_starts_with($item['loc'], 'http')
                        ? $this->pathResolver->normaliseUrl($item['loc'])
                        : $this->pathResolver->normaliseUrl($baseUrl . '/' . ltrim($item['loc'], '/'));
                    $entry = ['loc' => $url];
                    if (isset($item['changefreq'])) {
                        $entry['changefreq'] = (string) $item['changefreq'];
                    }
                    if (isset($item['priority'])) {
                        $entry['priority'] = (float) $item['priority'];
                    }
                    $links[] = $entry;
                }
            }
            return $links;
        }

        return [];
    }

    public function loadProfile(int $profileId): ?array
    {
        $conn  = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('panth_seo_sitemap_profile');

        if (!$conn->isTableExists($table)) {
            $this->ensureProfileTable($conn, $table);
        }

        $select = $conn->select()->from($table)->where('profile_id = ?', $profileId);
        $row = $conn->fetchRow($select);

        return is_array($row) && !empty($row) ? $row : null;
    }

    public function loadActiveProfiles(?int $storeId = null, bool $cronOnly = false): array
    {
        $conn  = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('panth_seo_sitemap_profile');

        if (!$conn->isTableExists($table)) {
            $this->ensureProfileTable($conn, $table);
        }

        $select = $conn->select()->from($table)->where('is_active = ?', 1);
        if ($storeId !== null) {
            $select->where('store_id = ?', $storeId);
        }
        if ($cronOnly) {
            $select->where('cron_enabled = ?', 1);
        }

        $rows = $conn->fetchAll($select);
        return is_array($rows) ? $rows : [];
    }

    public function updateProfileStats(int $profileId, array $stats): void
    {
        $conn  = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('panth_seo_sitemap_profile');

        if (!$conn->isTableExists($table)) {
            return;
        }

        $conn->update($table, [
            'last_generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'generation_time'   => $stats['generation_time'] ?? 0,
            'url_count'         => $stats['url_count'] ?? 0,
            'file_count'        => $stats['file_count'] ?? 0,
        ], ['profile_id = ?' => $profileId]);
    }

    private function ensureProfileTable($conn, string $table): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `profile_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `store_id` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `cron_enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `exclude_out_of_stock` TINYINT(1) NOT NULL DEFAULT 0,
            `exclude_noindex` TINYINT(1) NOT NULL DEFAULT 0,
            `include_images` TINYINT(1) NOT NULL DEFAULT 1,
            `include_hreflang` TINYINT(1) NOT NULL DEFAULT 0,
            `include_video` TINYINT(1) NOT NULL DEFAULT 0,
            `max_urls_per_file` INT UNSIGNED NOT NULL DEFAULT 50000,
            `product_changefreq` VARCHAR(20) DEFAULT 'weekly',
            `product_priority` DECIMAL(2,1) DEFAULT 0.8,
            `category_changefreq` VARCHAR(20) DEFAULT 'weekly',
            `category_priority` DECIMAL(2,1) DEFAULT 0.7,
            `cms_page_changefreq` VARCHAR(20) DEFAULT 'monthly',
            `cms_page_priority` DECIMAL(2,1) DEFAULT 0.5,
            `custom_changefreq` VARCHAR(20) DEFAULT 'weekly',
            `custom_priority` DECIMAL(2,1) DEFAULT 0.5,
            `custom_links` TEXT DEFAULT NULL,
            `entity_settings` TEXT DEFAULT NULL,
            `last_generated_at` DATETIME DEFAULT NULL,
            `generation_time` DECIMAL(10,2) DEFAULT NULL,
            `url_count` INT UNSIGNED DEFAULT 0,
            `file_count` INT UNSIGNED DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`profile_id`),
            KEY `idx_store_active` (`store_id`, `is_active`),
            KEY `idx_cron_enabled` (`cron_enabled`, `is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Panth SEO Sitemap Profiles'";

        $conn->query($sql);
    }

    private function pingSearchEngines(int $storeId, string $sitemapUrl): void
    {
        if ($sitemapUrl === '') {
            return;
        }

        $encodedUrl = urlencode($sitemapUrl);

        if ($this->config->isSitemapPingGoogleEnabled($storeId)) {
            try {
                $this->httpClient->get('https://www.google.com/ping?sitemap=' . $encodedUrl);
                $this->logger->info('[PanthSEO] Pinged Google with sitemap: ' . $sitemapUrl);
            } catch (\Throwable $e) {
                $this->logger->warning('[PanthSEO] Failed to ping Google: ' . $e->getMessage());
            }
        }

        if ($this->config->isSitemapPingBingEnabled($storeId)) {
            try {
                $this->httpClient->get('https://www.bing.com/ping?sitemap=' . $encodedUrl);
                $this->logger->info('[PanthSEO] Pinged Bing with sitemap: ' . $sitemapUrl);
            } catch (\Throwable $e) {
                $this->logger->warning('[PanthSEO] Failed to ping Bing: ' . $e->getMessage());
            }
        }
    }

    private function writeXslStylesheet(string $absDir): void
    {
        $xslPath = rtrim($absDir, '/') . '/' . self::XSL_FILENAME;
        $content = $this->xslStylesheet->getStylesheet();

        try {
            $written = file_put_contents($xslPath, $content);
            if ($written === false) {
                $this->logger->warning('[PanthSEO] Failed to write XSL stylesheet to: ' . $xslPath);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthSEO] Failed to write XSL stylesheet to: ' . $xslPath . ' - ' . $e->getMessage());
        }
    }

    private function removeStaleIndex(string $absDir, string $indexFilename): void
    {
        foreach (array_unique(array_merge([$indexFilename], self::KNOWN_INDEX_FILENAMES)) as $name) {
            $file = rtrim($absDir, '/') . '/' . $name;
            if (!file_exists($file)) {
                continue;
            }
            try {
                unlink($file);
            } catch (\Throwable) {
            }
        }
    }
}
