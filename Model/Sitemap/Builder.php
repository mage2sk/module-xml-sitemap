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
use Psr\Log\LoggerInterface;

/**
 * Streaming sitemap builder. Iterates contributors, writes shards at
 * shard_size boundary, emits sitemap_index.xml. Never buffers full lists.
 *
 * Output directory: pub/sitemap/<store_code>/
 *
 * When `panth_xml_sitemap/generation/xsl_enabled` is active, writes a human-readable XSL
 * stylesheet next to the shard files and references it via an
 * `<?xml-stylesheet?>` processing instruction in every shard.
 */
class Builder implements BuilderInterface
{
    private const XSL_FILENAME = 'sitemap-style.xsl';

    /** Max sitemap file size per Google spec: 50 MB */
    private const MAX_FILE_SIZE_BYTES = 50 * 1024 * 1024;

    /** Entity type to file prefix mapping */
    private const ENTITY_PREFIX_MAP = [
        'product'  => 'sitemap-products',
        'category' => 'sitemap-categories',
        'cms_page' => 'sitemap-cms',
        'custom'   => 'sitemap-custom',
    ];

    /**
     * @param array<string,ContributorInterface> $contributors
     */
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
        $relDir = 'sitemap/' . $storeCode;
        $pub->create($relDir);
        $absDir = $pub->getAbsolutePath($relDir);

        // Clean old shard files for this store (keep directory)
        foreach (glob(rtrim($absDir, '/') . '/sitemap-*.xml') ?: [] as $old) {
            if (file_exists($old)) {
                try {
                    unlink($old);
                } catch (\Throwable) {
                    // Best-effort cleanup
                }
            }
        }
        $indexFile = rtrim($absDir, '/') . '/sitemap_index.xml';
        if (file_exists($indexFile)) {
            try {
                unlink($indexFile);
            } catch (\Throwable) {
                // Best-effort cleanup
            }
        }

        // XSL stylesheet support
        $xslEnabled = $this->config->isSitemapXslEnabled($storeId);
        $xslHref    = $xslEnabled ? self::XSL_FILENAME : null;

        if ($xslEnabled) {
            $this->writeXslStylesheet($absDir);
        }

        /** @var array<int,array{loc:string,lastmod:string}> $shards */
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

        $closeShard = function () use (&$shard, &$shards, &$files, $storeCode, $baseUrl, $now): void {
            if ($shard === null) {
                return;
            }
            $path = $shard->close();
            $files[] = $path;
            $filename = basename($path);
            $shards[] = [
                'loc'     => $baseUrl . '/sitemap/' . $storeCode . '/' . $filename,
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

            // Ping search engines if enabled
            if (!empty($shards)) {
                $sitemapUrl = $shards[0]['loc'] ?? '';
                if (count($shards) > 1) {
                    // Use the sitemap index URL when multiple shards exist
                    $sitemapUrl = $baseUrl . '/sitemap/' . $storeCode . '/sitemap_index.xml';
                }
                $this->pingSearchEngines($storeId, $sitemapUrl);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[PanthSEO] sitemap build failed: ' . $e->getMessage());
            if ($shard !== null) {
                try {
                    $shard->close();
                } catch (\Throwable) {
                    // ignore
                }
            }
            throw $e;
        }

        return $files;
    }

    /**
     * Build sitemap for the given store and return the XML body as a string.
     *
     * Iterates all contributors and produces a single in-memory urlset document
     * so the frontend controller can serve it without writing files to disk.
     */
    public function buildForStore(int $storeId): string
    {
        $store   = $this->storeManager->getStore($storeId);
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');

        $xslEnabled = $this->config->isSitemapXslEnabled($storeId);

        // Load the active profile for this store (store-specific first, then
        // the "All Stores" profile) so the live `/panth-sitemap.xml` endpoint
        // respects the same entity_types / flags / custom_links the CLI
        // generator uses.
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
        ];
        $profileConfig = $profile ? [
            'exclude_out_of_stock'   => (bool) ($profile['exclude_out_of_stock'] ?? false),
            'exclude_noindex'        => (bool) ($profile['exclude_noindex'] ?? false),
            'include_images'         => (bool) ($profile['include_images'] ?? true),
            'include_hreflang_tags'  => (bool) ($profile['include_hreflang_tags'] ?? true),
            'include_video_sitemap'  => (bool) ($profile['include_video_sitemap'] ?? true),
            'priority_homepage'      => isset($profile['priority_homepage'])
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
            $xml->writePi(
                'xml-stylesheet',
                'type="text/xsl" href="' . htmlspecialchars(
                    $baseUrl . '/sitemap/' . $store->getCode() . '/' . self::XSL_FILENAME,
                    ENT_XML1 | ENT_QUOTES,
                    'UTF-8'
                ) . '"'
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
                    $loc = (string) $url['loc'];
                    // Dedup across contributors so CMS + LandingPage + Blog
                    // can't emit the same URL twice.
                    if (isset($seenLocs[$loc])) {
                        continue;
                    }
                    $seenLocs[$loc] = true;
                    $xml->startElement('url');
                    $xml->writeElement('loc', $loc);
                    if (!empty($url['lastmod'])) {
                        $xml->writeElement('lastmod', (string) $url['lastmod']);
                    }
                    if (!empty($url['changefreq'])) {
                        $xml->writeElement('changefreq', (string) $url['changefreq']);
                    }
                    if (isset($url['priority'])) {
                        $xml->writeElement('priority', number_format((float) $url['priority'], 1, '.', ''));
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
                    $xml->endElement(); // url
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    '[PanthSEO] sitemap contributor "' . $contributor->getCode() . '" failed: ' . $e->getMessage()
                );
            }
        }

        // Emit profile-configured custom_links + additional_links system-config
        // after contributor URLs so `/panth-sitemap.xml` matches what the CLI
        // writes when 'custom' is selected in entity_types.
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
                if (!empty($link['changefreq'])) {
                    $xml->writeElement('changefreq', (string) $link['changefreq']);
                }
                if (isset($link['priority'])) {
                    $xml->writeElement('priority', number_format((float) $link['priority'], 1, '.', ''));
                }
                $xml->endElement();
            }
        }

        $xml->endElement(); // urlset
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * Load the active sitemap profile for a given store. Prefers a profile
     * whose `store_id` matches exactly, falls back to the "All Stores"
     * profile (`store_id = 0`). Returns null when neither exists.
     *
     * Used by `buildForStore` so the live `/panth-sitemap.xml` endpoint
     * honours the same entity_types / flags / custom_links the CLI
     * generator applies from `buildFromProfile`.
     *
     * @return array<string,mixed>|null
     */
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

    /**
     * Build sitemaps from a sitemap profile configuration.
     *
     * Generates separate files per entity type and combines them into sitemap_index.xml.
     * Respects profile settings for exclusions, images, changefreq, priority, and max URLs.
     *
     * @param array $profile Row from panth_seo_sitemap_profile table
     * @return array{url_count: int, file_count: int, generation_time: float, files: list<string>}
     */
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

        // Profile-level settings. `include_hreflang_tags` / `include_video_sitemap`
        // gate the xhtml/video xmlns declarations in the generated shards, and
        // `priority_homepage` overrides the hard-coded 1.0 default in
        // ProductContributor::homepage-optimisation branch.
        $profileConfig = [
            'exclude_out_of_stock'   => (bool) ($profile['exclude_out_of_stock'] ?? false),
            'exclude_noindex'        => (bool) ($profile['exclude_noindex'] ?? false),
            'include_images'         => (bool) ($profile['include_images'] ?? true),
            'include_hreflang_tags'  => (bool) ($profile['include_hreflang_tags'] ?? true),
            'include_video_sitemap'  => (bool) ($profile['include_video_sitemap'] ?? true),
            'priority_homepage'      => isset($profile['priority_homepage'])
                ? (float) $profile['priority_homepage']
                : null,
        ];

        // Per-entity changefreq/priority from profile (JSON-decoded or direct columns)
        $entitySettings = $this->resolveEntitySettings($profile);

        // Output path honours the profile's `output_path` template with the
        // `{store_code}` placeholder. Default keeps the path short —
        // `sitemap/<store_code>/` — so `sitemap_index.xml` lands one folder
        // deep. Multi-store installs still avoid collisions via the store
        // subdirectory.
        $outputPath = trim((string) ($profile['output_path'] ?? ''));
        if ($outputPath !== '') {
            $profileDir = rtrim(strtr($outputPath, ['{store_code}' => $storeCode]), '/');
        } else {
            $profileDir = 'sitemap/' . $storeCode;
        }
        $pub = $this->filesystem->getDirectoryWrite(DirectoryList::PUB);
        $pub->create($profileDir);
        $absDir = $pub->getAbsolutePath($profileDir);

        // Clean old shard files for this profile
        foreach (glob(rtrim($absDir, '/') . '/sitemap-*.xml') ?: [] as $old) {
            if (file_exists($old)) {
                try {
                    unlink($old);
                } catch (\Throwable) {
                    // Best-effort cleanup
                }
            }
        }
        $indexFile = rtrim($absDir, '/') . '/sitemap_index.xml';
        if (file_exists($indexFile)) {
            try {
                unlink($indexFile);
            } catch (\Throwable) {
                // Best-effort cleanup
            }
        }

        // XSL stylesheet support
        $xslEnabled = $this->config->isSitemapXslEnabled($storeId);
        $xslHref    = $xslEnabled ? self::XSL_FILENAME : null;

        if ($xslEnabled) {
            $this->writeXslStylesheet($absDir);
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');

        /** @var array<int,array{loc:string,lastmod:string}> $indexEntries */
        $indexEntries  = [];
        $allFiles      = [];
        $totalUrlCount = 0;

        // Map contributor codes to entity type prefixes
        $contributorEntityMap = [
            'product'  => 'product',
            'category' => 'category',
            'cms_page' => 'cms_page',
        ];

        // Profile-level entity-type filter. Empty = include everything (back-compat).
        // Contributors not mapped into one of the four admin buckets
        // (product / category / cms / custom) are treated as always-on
        // decorators (hreflang, image, video additions run alongside their
        // host entity) and skipped from this gating.
        $allowedBuckets = $this->resolveEntityBuckets((string) ($profile['entity_types'] ?? ''));
        $contributorBucketMap = [
            'product'       => 'product',
            'category'      => 'category',
            'cms_page'      => 'cms',
            'landing_page'  => 'product',
            'blog'          => 'cms',
        ];

        // Generate files per contributor (each entity type gets its own shard series)
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

            // Build config for this contributor from profile
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

        // Handle custom links from profile, gated on the profile's entity_types
        // when the filter is set ('custom' must be one of the allowed buckets).
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

        // Write sitemap_index.xml
        if (!empty($indexEntries)) {
            $this->indexWriter->write($indexFile, $indexEntries, $xslHref);
            $allFiles[] = $indexFile;
        }

        $this->deltaTracker->mark($storeId, $now);

        // Ping search engines
        if (!empty($indexEntries)) {
            $sitemapUrl = $baseUrl . '/' . $profileDir . '/sitemap_index.xml';
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

    /**
     * Write shard files for a single entity type contributor.
     *
     * @return array{shards: list<array{loc:string,lastmod:string}>, files: list<string>, url_count: int}
     */
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

        // Propagate hreflang + video xmlns toggles from the profile config
        // so the resulting shards only declare the namespaces they actually use.
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

        $closeShard = function () use (&$shard, &$shards, &$files, $baseUrl, $profileDir, $now): void {
            if ($shard === null) {
                return;
            }
            $path = $shard->close();
            $files[] = $path;
            $filename = basename($path);
            $shards[] = [
                'loc'     => $baseUrl . '/' . $profileDir . '/' . $filename,
                'lastmod' => $now,
            ];
            $shard = null;
        };

        foreach ($contributor->getUrls($storeId, $config) as $url) {
            if (!is_array($url) || empty($url['loc'])) {
                continue;
            }

            // Ensure lastmod is present (Google 2026 best practice)
            if (empty($url['lastmod'])) {
                $url['lastmod'] = $now;
            }

            if ($shard === null) {
                $openShard();
            }

            $shard->writeUrl($url);
            $shardUrlCount++;
            $urlCount++;

            // Check URL count limit and file size limit (50MB)
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

    /**
     * Write shard files for custom links.
     *
     * @param list<array{loc:string,changefreq?:string,priority?:float}> $links
     * @return array{shards: list<array{loc:string,lastmod:string}>, files: list<string>, url_count: int}
     */
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

        // Custom links never carry image/hreflang/video extensions; skip the
        // auxiliary xmlns declarations to keep the shard minimal.
        $customShardOptions = ['include_hreflang' => false, 'include_video' => false];

        $openShard = function () use (&$shard, &$shardIdx, &$shardUrlCount, $absDir, $prefix, $xslHref, $customShardOptions): void {
            $shardIdx++;
            $path = rtrim($absDir, '/') . '/' . $prefix . '-' . $shardIdx . '.xml';
            $shard = $this->shardFactory->create();
            $shard->open($path, $xslHref, $customShardOptions);
            $shardUrlCount = 0;
        };

        $closeShard = function () use (&$shard, &$shards, &$files, $baseUrl, $profileDir, $now): void {
            if ($shard === null) {
                return;
            }
            $path = $shard->close();
            $files[] = $path;
            $filename = basename($path);
            $shards[] = [
                'loc'     => $baseUrl . '/' . $profileDir . '/' . $filename,
                'lastmod' => $now,
            ];
            $shard = null;
        };

        foreach ($links as $link) {
            if (empty($link['loc'])) {
                continue;
            }

            $url = [
                'loc'        => $link['loc'],
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

    /**
     * Resolve per-entity changefreq/priority settings from profile data.
     *
     * Supports both JSON-encoded `entity_settings` column and direct columns like
     * `product_changefreq`, `product_priority`, `category_changefreq`, etc.
     *
     * @return array<string, array{changefreq?: string, priority?: float}>
     */
    private function resolveEntitySettings(array $profile): array
    {
        // Try JSON column first
        if (!empty($profile['entity_settings'])) {
            $decoded = is_string($profile['entity_settings'])
                ? json_decode($profile['entity_settings'], true)
                : $profile['entity_settings'];
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Fall back to direct columns
        // Supports both naming conventions:
        //   product_changefreq / product_priority  (table schema)
        //   changefreq_product / priority_product   (actual DB columns)
        $settings = [];
        // Map entity types to their possible short names in DB columns
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
                // Convention 1: <alias>_changefreq / <alias>_priority
                $cf = $cf ?? ($profile[$alias . '_changefreq'] ?? null);
                $pr = $pr ?? ($profile[$alias . '_priority'] ?? null);
                // Convention 2: changefreq_<alias> / priority_<alias>
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

    /**
     * Parse custom links from the profile.
     *
     * @return list<array{loc:string, changefreq?:string, priority?:float}>
     */
    /**
     * Parse the profile's `entity_types` multiselect value into a set of
     * allowed admin buckets (product / category / cms / custom).
     *
     * Returns null when the filter is empty — meaning "include everything"
     * (back-compat for profiles seeded before the filter landed). Otherwise
     * returns a map where keys are the allowed buckets.
     *
     * @return array<string,true>|null
     */
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

        // Try JSON first
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                // Newline-separated. Each line is either
                //   https://example.com/path
                // or a 2-3-column CSV
                //   https://example.com/path,weekly,0.7
                // (changefreq + priority are optional).
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
                    $url = str_starts_with($loc, 'http') ? $loc : $baseUrl . '/' . ltrim($loc, '/');
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
                    $url = str_starts_with($item, 'http') ? $item : $baseUrl . '/' . ltrim($item, '/');
                    $links[] = ['loc' => $url];
                } elseif (is_array($item) && !empty($item['loc'])) {
                    $url = str_starts_with($item['loc'], 'http')
                        ? $item['loc']
                        : $baseUrl . '/' . ltrim($item['loc'], '/');
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

    /**
     * Load a sitemap profile from the database by ID.
     *
     * Creates the profile table if it does not yet exist.
     *
     * @return array|null Profile row or null if not found
     */
    public function loadProfile(int $profileId): ?array
    {
        $conn  = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('panth_seo_sitemap_profile');

        // Ensure table exists
        if (!$conn->isTableExists($table)) {
            $this->ensureProfileTable($conn, $table);
        }

        $select = $conn->select()->from($table)->where('profile_id = ?', $profileId);
        $row = $conn->fetchRow($select);

        return is_array($row) && !empty($row) ? $row : null;
    }

    /**
     * Load all active profiles, optionally filtered by store.
     *
     * @return list<array>
     */
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

    /**
     * Update profile row with generation stats.
     */
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

    /**
     * Create the panth_seo_sitemap_profile table if it does not exist.
     */
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

    /**
     * Ping Google and/or Bing with the sitemap URL after a successful build.
     */
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

    /**
     * Write the XSL stylesheet file into the sitemap output directory.
     */
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
}
