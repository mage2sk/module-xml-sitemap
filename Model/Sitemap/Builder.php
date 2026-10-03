<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Sitemap;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;
use Panth\XmlSitemap\Api\BuilderInterface;
use Panth\XmlSitemap\Api\ContributorInterface;
use Panth\XmlSitemap\Api\HreflangResolverInterface;
use Panth\XmlSitemap\Helper\Config;
use Panth\XmlSitemap\Helper\PathResolver;
use Psr\Log\LoggerInterface;

class Builder implements BuilderInterface
{
    public const PUBLIC_SITEMAP_PATH = 'panth-sitemap.xml';

    public const PUBLIC_PAGE_PARAM = 'page';

    private const KNOWN_INDEX_FILENAMES = ['sitemap.xml', 'sitemap_index.xml'];

    private const XSL_FILENAME = 'sitemap-style.xsl';

    private const MAX_FILE_SIZE_BYTES = 50 * 1024 * 1024;

    private const MAX_URLS_PER_FILE = 50000;

    private const PUBLIC_SIZE_MARGIN_BYTES = 65536;

    private const HREFLANG_ENTITY_TYPES = ['product', 'category', 'cms'];

    private const ENTITY_PREFIX_MAP = [
        'product'      => 'sitemap-products',
        'category'     => 'sitemap-categories',
        'cms_page'     => 'sitemap-cms',
        'custom'       => 'sitemap-custom',

        'testimonial'  => 'sitemap-testimonials',
        'faq'          => 'sitemap-faqs',
        'dynamic_form' => 'sitemap-dynamic-forms',
    ];

    private const CONTRIBUTOR_ENTITY_MAP = [
        'product'      => 'product',
        'category'     => 'category',
        'cms_page'     => 'cms_page',
        'testimonial'  => 'testimonial',
        'faq'          => 'faq',
        'dynamic_form' => 'dynamic_form',
    ];

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Filesystem $filesystem,
        private readonly ShardWriterFactory $shardFactory,
        private readonly IndexWriter $indexWriter,
        private readonly DeltaTracker $deltaTracker,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly XslStylesheet $xslStylesheet,
        private readonly ResourceConnection $resourceConnection,
        private readonly PathResolver $pathResolver,
        private readonly UrlElementWriter $urlElementWriter,
        private readonly HreflangResolverInterface $hreflangResolver,
        private readonly array $contributors = []
    ) {
    }

    public function build(int $storeId): iterable
    {
        $store     = $this->storeManager->getStore($storeId);
        $storeCode = (string) $store->getCode();
        $shardSize = $this->config->getSitemapShardSize($storeId);
        $baseUrl   = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, $store->isUrlSecure()), '/');

        $pub = $this->filesystem->getDirectoryWrite(DirectoryList::PUB);

        $relDir = 'xmlsitemap/' . $storeCode;
        $pub->create($relDir);
        $absDir = $pub->getAbsolutePath($relDir);

        $indexFilename = $this->config->getSitemapIndexFilename($storeId);
        $indexFile = rtrim($absDir, '/') . '/' . $indexFilename;
        $this->cleanOutputDir($absDir, false, $indexFilename);

        $xslEnabled = $this->config->isSitemapXslEnabled($storeId);
        $xslHref    = $xslEnabled ? self::XSL_FILENAME : null;

        if ($xslEnabled) {
            $this->writeXslStylesheet($absDir);
        }

        $includeHreflang = $this->config->sitemapIncludeHreflang($storeId);
        $shardOptions = $this->buildShardOptions(
            $storeId,
            $includeHreflang,
            $this->config->sitemapIncludeVideo($storeId)
        );

        $shards = [];
        $files  = [];

        $shardIdx = 0;
        $urlCount = 0;
        $shard    = null;
        $now      = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');

        $openShard = function () use (&$shard, &$shardIdx, &$urlCount, $absDir, $xslHref, $shardOptions): void {
            $shardIdx++;
            $path = rtrim($absDir, '/') . '/sitemap-' . $shardIdx . '.xml';
            $shard = $this->shardFactory->create();
            $shard->open($path, $xslHref, $shardOptions);
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
                        $url = $this->attachHreflang($url, $storeId, $includeHreflang);
                        if ($shard === null) {
                            $openShard();
                        }
                        $shard->writeUrl($url);
                        $urlCount++;
                        if ($urlCount >= $shardSize || $shard->getFileSize() >= self::MAX_FILE_SIZE_BYTES) {
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
        $parts = $this->buildPublicSitemap($storeId);
        return (string) ($parts['index'] ?? ($parts['pages'][1] ?? ''));
    }

    public function buildPublicSitemap(int $storeId): array
    {
        $store   = $this->storeManager->getStore($storeId);
        $baseUrl = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, $store->isUrlSecure()), '/');

        $xslEnabled = $this->config->isSitemapXslEnabled($storeId);
        $xslUrl     = null;
        if ($xslEnabled) {
            $xslUrl = $this->pathResolver->buildSitemapUrl(
                $baseUrl,
                'xmlsitemap/' . trim((string) $store->getCode(), '/'),
                self::XSL_FILENAME
            );
        }

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
        $profileConfig = $profile ? $this->buildProfileConfig($profile) : [];
        $entitySettings = $profile ? $this->resolveEntitySettings($profile) : [];
        $includeHreflang = $profile
            ? (bool) $profileConfig['include_hreflang_tags']
            : $this->config->sitemapIncludeHreflang($storeId);
        $includeVideo = (bool) ($profileConfig['include_video_sitemap'] ?? true);
        $options = [
            'include_hreflang'            => $includeHreflang,
            'include_video'               => $includeVideo,
            'include_changefreq_priority' => $this->config->sitemapIncludeChangefreqPriority($storeId),
        ];

        $pages   = [];
        $xml     = null;
        $buffer  = '';
        $inPage  = 0;

        $openPage = function () use (&$xml, &$buffer, &$inPage, $xslUrl, $options): void {
            $xml = new \XMLWriter();
            $xml->openMemory();
            $xml->setIndent(true);
            $xml->setIndentString('  ');
            $xml->startDocument('1.0', 'UTF-8');
            if ($xslUrl !== null) {
                $xml->writePi(
                    'xml-stylesheet',
                    'type="text/xsl" href="' . htmlspecialchars($xslUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"'
                );
            }
            $this->urlElementWriter->writeUrlsetStart($xml, $options);
            $buffer = '';
            $inPage = 0;
        };
        $closePage = function () use (&$xml, &$buffer, &$pages): void {
            if ($xml === null) {
                return;
            }
            $xml->endElement();
            $xml->endDocument();
            $buffer .= $xml->outputMemory(true);
            $pages[count($pages) + 1] = $buffer;
            $xml = null;
            $buffer = '';
        };
        $writeUrl = function (array $url) use (&$xml, &$buffer, &$inPage, $openPage, $closePage, $options): void {
            if ($xml === null) {
                $openPage();
            }
            if ($this->urlElementWriter->write($xml, $url, $options)) {
                $inPage++;
            }
            $buffer .= $xml->outputMemory(true);
            if ($inPage >= self::MAX_URLS_PER_FILE
                || strlen($buffer) >= self::MAX_FILE_SIZE_BYTES - self::PUBLIC_SIZE_MARGIN_BYTES
            ) {
                $closePage();
            }
        };

        $seenLocs = [];

        foreach ($this->contributors as $contributor) {
            if (!$contributor instanceof ContributorInterface) {
                continue;
            }

            $code = $contributor->getCode();
            if ($allowedBuckets !== null) {
                $bucket = $contributorBucketMap[$code] ?? null;
                if ($bucket !== null && !isset($allowedBuckets[$bucket])) {
                    continue;
                }
            }

            $contributorConfig = $profileConfig;
            $entityType = self::CONTRIBUTOR_ENTITY_MAP[$code] ?? $code;
            if (isset($entitySettings[$entityType])) {
                $contributorConfig = array_merge($contributorConfig, $entitySettings[$entityType]);
            }

            try {
                foreach ($contributor->getUrls($storeId, $contributorConfig) as $url) {
                    if (!is_array($url) || empty($url['loc'])) {
                        continue;
                    }
                    $loc = $this->pathResolver->normaliseUrl((string) $url['loc']);

                    if ($loc === '' || isset($seenLocs[$loc])) {
                        continue;
                    }
                    $seenLocs[$loc] = true;
                    $url['loc'] = $loc;
                    $writeUrl($this->attachHreflang($url, $storeId, $includeHreflang));
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    '[PanthSEO] sitemap contributor "' . $code . '" failed: ' . $e->getMessage()
                );
            }
        }

        if ($allowedBuckets === null || isset($allowedBuckets['custom'])) {
            $customLinks = $profile ? $this->resolveCustomLinks($profile, $baseUrl) : [];
            $customDefaults = $entitySettings['custom'] ?? [];
            foreach ($customLinks as $link) {
                $loc = (string) ($link['loc'] ?? '');
                if ($loc === '' || isset($seenLocs[$loc])) {
                    continue;
                }
                $seenLocs[$loc] = true;
                $writeUrl([
                    'loc'        => $loc,
                    'changefreq' => $link['changefreq'] ?? ($customDefaults['changefreq'] ?? 'weekly'),
                    'priority'   => $link['priority'] ?? ($customDefaults['priority'] ?? 0.5),
                ]);
            }
        }

        if ($xml === null && $pages === []) {
            $openPage();
        }
        $closePage();

        $index = null;
        if (count($pages) > 1) {
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
            $index = $this->buildPublicIndex($baseUrl, count($pages), $now, $xslUrl);
        }

        return [
            'index' => $index,
            'pages' => $pages,
        ];
    }

    private function buildPublicIndex(string $baseUrl, int $pageCount, string $now, ?string $xslUrl): string
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');
        if ($xslUrl !== null) {
            $xml->writePi(
                'xml-stylesheet',
                'type="text/xsl" href="' . htmlspecialchars($xslUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"'
            );
        }
        $xml->startElement('sitemapindex');
        $xml->writeAttribute('xmlns', UrlElementWriter::NS_SITEMAP);
        for ($page = 1; $page <= $pageCount; $page++) {
            $xml->startElement('sitemap');
            $xml->writeElement(
                'loc',
                $baseUrl . '/' . self::PUBLIC_SITEMAP_PATH . '?' . self::PUBLIC_PAGE_PARAM . '=' . $page
            );
            $xml->writeElement('lastmod', $now);
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();
        return $xml->outputMemory(true);
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
                ->order('profile_id ASC')
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

        $store     = $this->resolveProfileStore((int) ($profile['store_id'] ?? 0));
        $storeId   = (int) $store->getId();
        $storeCode = (string) $store->getCode();
        $baseUrl   = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, $store->isUrlSecure()), '/');

        $maxUrlsPerFile = (int) ($profile['max_urls_per_file'] ?? self::MAX_URLS_PER_FILE);
        if ($maxUrlsPerFile <= 0 || $maxUrlsPerFile > self::MAX_URLS_PER_FILE) {
            $maxUrlsPerFile = self::MAX_URLS_PER_FILE;
        }

        $profileConfig = $this->buildProfileConfig($profile);

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

        $indexFilename = $this->config->getSitemapIndexFilename($storeId);
        $indexFile = rtrim($absDir, '/') . '/' . $indexFilename;
        $this->cleanOutputDir($absDir, $profileDir === '', $indexFilename);

        $xslEnabled = $this->config->isSitemapXslEnabled($storeId);
        $xslHref    = $xslEnabled ? self::XSL_FILENAME : null;

        if ($xslEnabled) {
            $this->writeXslStylesheet($absDir);
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');

        $indexEntries  = [];
        $allFiles      = [];
        $totalUrlCount = 0;

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
            $entityType = self::CONTRIBUTOR_ENTITY_MAP[$code] ?? $code;
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
                $storeId,
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

        $generationTime = round(microtime(true) - $startTime, 2);

        return [
            'url_count'       => $totalUrlCount,
            'file_count'      => count($allFiles),
            'generation_time' => $generationTime,
            'files'           => $allFiles,
        ];
    }

    private function resolveProfileStore(int $storeId): StoreInterface
    {
        if ($storeId > 0) {
            return $this->storeManager->getStore($storeId);
        }
        $default = $this->storeManager->getDefaultStoreView();
        if ($default !== null && (int) $default->getId() > 0) {
            return $default;
        }
        return $this->storeManager->getStore($storeId);
    }

    private function buildProfileConfig(array $profile): array
    {
        return [
            'exclude_out_of_stock'     => (bool) ($profile['exclude_out_of_stock'] ?? false),
            'exclude_noindex'          => (bool) ($profile['exclude_noindex'] ?? false),
            'excluded_cms_identifiers' => (string) ($profile['excluded_cms_identifiers'] ?? ''),
            'include_images'           => (bool) ($profile['include_images'] ?? true),
            'include_hreflang_tags'    => (bool) ($profile['include_hreflang_tags'] ?? $profile['include_hreflang'] ?? true),
            'include_video_sitemap'    => (bool) ($profile['include_video_sitemap'] ?? $profile['include_video'] ?? true),
            'priority_homepage'        => isset($profile['priority_homepage'])
                ? (float) $profile['priority_homepage']
                : null,
        ];
    }

    private function buildShardOptions(int $storeId, bool $includeHreflang, bool $includeVideo): array
    {
        return [
            'include_hreflang'            => $includeHreflang,
            'include_video'               => $includeVideo,
            'include_changefreq_priority' => $this->config->sitemapIncludeChangefreqPriority($storeId),
            'gzip'                        => $this->config->sitemapGzip($storeId),
        ];
    }

    private function attachHreflang(array $url, int $storeId, bool $includeHreflang): array
    {
        if (!$includeHreflang || !empty($url['hreflang'])) {
            return $url;
        }
        $entityType = (string) ($url['entity_type'] ?? '');
        $entityId   = (int) ($url['entity_id'] ?? 0);
        if ($entityId <= 0 || !in_array($entityType, self::HREFLANG_ENTITY_TYPES, true)) {
            return $url;
        }
        try {
            $alternates = $this->hreflangResolver->getAlternates($entityType, $entityId, $storeId);
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthXmlSitemap] hreflang lookup failed: ' . $e->getMessage());
            return $url;
        }
        if ($alternates !== []) {
            $url['hreflang'] = $alternates;
        }
        return $url;
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

        $includeHreflang = (bool) ($config['include_hreflang_tags'] ?? true);
        $shardOptions = $this->buildShardOptions(
            $storeId,
            $includeHreflang,
            (bool) ($config['include_video_sitemap'] ?? true)
        );

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

            $url = $this->attachHreflang($url, $storeId, $includeHreflang);

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
        int $storeId,
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

        $customShardOptions = $this->buildShardOptions($storeId, false, false);

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
            `name` VARCHAR(255) NOT NULL,
            `store_id` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `entity_types` VARCHAR(512) NOT NULL DEFAULT 'product,category,cms',
            `include_images` TINYINT(1) NOT NULL DEFAULT 1,
            `include_video` TINYINT(1) NOT NULL DEFAULT 0,
            `include_hreflang` TINYINT(1) NOT NULL DEFAULT 0,
            `max_urls_per_file` INT UNSIGNED NOT NULL DEFAULT 50000,
            `changefreq_product` VARCHAR(32) NOT NULL DEFAULT 'weekly',
            `changefreq_category` VARCHAR(32) NOT NULL DEFAULT 'weekly',
            `changefreq_cms` VARCHAR(32) NOT NULL DEFAULT 'monthly',
            `priority_product` DECIMAL(2,1) NOT NULL DEFAULT 0.8,
            `priority_category` DECIMAL(2,1) NOT NULL DEFAULT 0.6,
            `priority_cms` DECIMAL(2,1) NOT NULL DEFAULT 0.5,
            `priority_homepage` DECIMAL(2,1) NOT NULL DEFAULT 1.0,
            `exclude_out_of_stock` TINYINT(1) NOT NULL DEFAULT 0,
            `exclude_noindex` TINYINT(1) NOT NULL DEFAULT 1,
            `excluded_cms_identifiers` VARCHAR(1024) DEFAULT NULL,
            `custom_links` TEXT DEFAULT NULL,
            `output_path` VARCHAR(512) NOT NULL DEFAULT 'xmlsitemap/{store_code}/',
            `last_generated_at` TIMESTAMP NULL DEFAULT NULL,
            `generation_time` INT UNSIGNED DEFAULT NULL,
            `url_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `file_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `cron_enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `cron_schedule` VARCHAR(64) NOT NULL DEFAULT '0 2 * * *',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`profile_id`),
            KEY `PANTH_SEO_SITEMAP_PROFILE_IDX_ACTIVE` (`is_active`),
            KEY `PANTH_SEO_SITEMAP_PROFILE_IDX_STORE` (`store_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Panth SEO Sitemap Profiles'";

        $conn->query($sql);
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

    private function cleanOutputDir(string $absDir, bool $isPubRoot, string $indexFilename): void
    {
        $dir = rtrim($absDir, '/');
        $patterns = [];
        if ($isPubRoot) {
            foreach ($this->getOwnShardPrefixes() as $prefix) {
                $patterns[] = $dir . '/' . $prefix . '-[0-9]*.xml';
                $patterns[] = $dir . '/' . $prefix . '-[0-9]*.xml.gz';
            }
            $indexNames = [$indexFilename];
        } else {
            $patterns[] = $dir . '/sitemap-*.xml';
            $patterns[] = $dir . '/sitemap-*.xml.gz';
            $indexNames = array_unique(array_merge([$indexFilename], self::KNOWN_INDEX_FILENAMES));
        }

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $old) {
                $this->deleteFile($old);
            }
        }
        foreach ($indexNames as $name) {
            $this->deleteFile($dir . '/' . $name);
        }
    }

    private function getOwnShardPrefixes(): array
    {
        $prefixes = array_values(self::ENTITY_PREFIX_MAP);
        foreach ($this->contributors as $contributor) {
            if (!$contributor instanceof ContributorInterface) {
                continue;
            }
            $code = $contributor->getCode();
            $entityType = self::CONTRIBUTOR_ENTITY_MAP[$code] ?? $code;
            $prefixes[] = self::ENTITY_PREFIX_MAP[$entityType] ?? ('sitemap-' . $entityType);
        }
        return array_values(array_unique($prefixes));
    }

    private function deleteFile(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        try {
            unlink($file);
        } catch (\Throwable) {
        }
    }
}
