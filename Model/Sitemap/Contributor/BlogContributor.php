<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Sitemap\Contributor;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\XmlSitemap\Api\ContributorInterface;
use Psr\Log\LoggerInterface;

class BlogContributor implements ContributorInterface
{
    private const CHANGEFREQ = 'weekly';
    private const PRIORITY   = 0.6;

    private const MAGEFAN_MODULE             = 'Magefan_Blog';
    private const MAGEFAN_POST_TABLE         = 'magefan_blog_post';
    private const MAGEFAN_POST_STORE_TABLE   = 'magefan_blog_post_store';
    private const MAGEFAN_TYPE_PATH          = 'mfblog/permalink/type';
    private const MAGEFAN_ROUTE_PATH         = 'mfblog/permalink/route';
    private const MAGEFAN_POST_ROUTE_PATH    = 'mfblog/permalink/post_route';
    private const MAGEFAN_SUFFIX_PATH        = 'mfblog/permalink/post_sufix';
    private const MAGEFAN_NO_SLASH_PATH      = 'mfblog/permalink/redirect_to_no_slash';
    private const MAGEFAN_DEFAULT_ROUTE      = 'blog';
    private const MAGEFAN_DEFAULT_POST_ROUTE = 'post';

    private const MAGEPLAZA_MODULE        = 'Mageplaza_Blog';
    private const MAGEPLAZA_POST_TABLE    = 'mageplaza_blog_post';
    private const MAGEPLAZA_ROUTE_PATHS   = ['blog/display/url_prefix', 'blog/general/url_prefix'];
    private const MAGEPLAZA_SUFFIX_PATHS  = ['blog/display/url_suffix', 'blog/general/url_suffix'];
    private const MAGEPLAZA_DEFAULT_ROUTE = 'blog';
    private const MAGEPLAZA_POST_TYPE     = 'post';

    private const PANTH_POST_TABLE      = 'panth_blog_post';
    private const PANTH_CATEGORY_TABLE  = 'panth_blog_category';
    private const PANTH_TAG_TABLE       = 'panth_blog_tag';
    private const PANTH_AUTHOR_TABLE    = 'panth_blog_author';
    private const PANTH_ROUTE_PATH      = 'panth_blog/general/route_frontname';
    private const PANTH_DEFAULT_ROUTE   = 'blog';
    private const PANTH_CHANGEFREQ_POST = 'weekly';
    private const PANTH_CHANGEFREQ_TAX  = 'weekly';
    private const PANTH_PRIORITY_POST   = 0.6;
    private const PANTH_PRIORITY_TAX    = 0.4;
    private const PANTH_PRIORITY_INDEX  = 0.7;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ModuleManager $moduleManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getCode(): string
    {
        return 'blog';
    }

    public function getUrls(int $storeId, array $config = []): \Generator
    {
        try {
            $conn = $this->resource->getConnection();
        } catch (\Throwable $e) {
            $this->logger->info('[Panth_XmlSitemap] blog contributor - db unavailable: ' . $e->getMessage());
            return;
        }

        $store   = $this->storeManager->getStore($storeId);
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';

        if ($conn->isTableExists($this->resource->getTableName(self::PANTH_POST_TABLE))) {
            yield from $this->getPanthBlogUrls($conn, $storeId, rtrim($baseUrl, '/'), $config);
            return;
        }

        $changefreq = $config['changefreq'] ?? self::CHANGEFREQ;
        $priority   = isset($config['priority']) ? (float) $config['priority'] : self::PRIORITY;

        if ($this->moduleManager->isEnabled(self::MAGEFAN_MODULE)
            && $conn->isTableExists($this->resource->getTableName(self::MAGEFAN_POST_TABLE))
        ) {
            yield from $this->getMagefanUrls($conn, $storeId, $baseUrl, $changefreq, $priority);
            return;
        }

        if ($this->moduleManager->isEnabled(self::MAGEPLAZA_MODULE)
            && $conn->isTableExists($this->resource->getTableName(self::MAGEPLAZA_POST_TABLE))
        ) {
            yield from $this->getMageplazaUrls($conn, $storeId, $baseUrl, $changefreq, $priority);
        }
    }

    private function getMagefanUrls(
        AdapterInterface $conn,
        int $storeId,
        string $baseUrl,
        string $changefreq,
        float $priority
    ): \Generator {
        try {
            $postTable  = $this->resource->getTableName(self::MAGEFAN_POST_TABLE);
            $storeTable = $this->resource->getTableName(self::MAGEFAN_POST_STORE_TABLE);

            $cols = $conn->describeTable($postTable);
            if (!isset($cols['identifier'])) {
                return;
            }

            $columns = ['post_id', 'identifier'];
            if (isset($cols['update_time'])) {
                $columns[] = 'update_time';
            }

            $select = $conn->select()
                ->from(['p' => $postTable], $columns)
                ->where('p.identifier IS NOT NULL')
                ->where('p.identifier != ?', '');
            if (isset($cols['is_active'])) {
                $select->where('p.is_active = ?', 1);
            }
            if (isset($cols['publish_time'])) {
                $select->where('p.publish_time IS NULL OR p.publish_time <= ?', new \Zend_Db_Expr('NOW()'));
            }
            if ($conn->isTableExists($storeTable)) {
                $select->join(
                    ['s' => $storeTable],
                    's.post_id = p.post_id AND s.store_id IN (0, ' . (int) $storeId . ')',
                    []
                )->group('p.post_id');
            }

            $type = (string) ($this->scopeConfig->getValue(self::MAGEFAN_TYPE_PATH, ScopeInterface::SCOPE_STORE, $storeId)
                ?: 'default');
            $route = trim((string) ($this->scopeConfig->getValue(self::MAGEFAN_ROUTE_PATH, ScopeInterface::SCOPE_STORE, $storeId)
                ?: self::MAGEFAN_DEFAULT_ROUTE), '/');
            $postRoute = trim((string) ($this->scopeConfig->getValue(self::MAGEFAN_POST_ROUTE_PATH, ScopeInterface::SCOPE_STORE, $storeId)
                ?: self::MAGEFAN_DEFAULT_POST_ROUTE), '/');
            $suffix  = trim((string) $this->scopeConfig->getValue(self::MAGEFAN_SUFFIX_PATH, ScopeInterface::SCOPE_STORE, $storeId));
            $noSlash = (bool) $this->scopeConfig->getValue(self::MAGEFAN_NO_SLASH_PATH, ScopeInterface::SCOPE_STORE, $storeId);

            $prefix = $type === 'short' ? $route : $route . '/' . $postRoute;

            $stmt = $conn->query($select);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $identifier = trim((string) ($row['identifier'] ?? ''), '/');
                if ($identifier === '') {
                    continue;
                }
                $loc = $baseUrl . $prefix . '/' . $identifier;
                if ($suffix !== '') {
                    $loc .= $suffix;
                } elseif (!$noSlash) {
                    $loc .= '/';
                }
                $entry = [
                    'loc'        => $loc,
                    'changefreq' => $changefreq,
                    'priority'   => $priority,
                ];
                if (!empty($row['update_time'])) {
                    $entry['lastmod'] = $this->formatLastmod((string) $row['update_time']);
                }
                yield $entry;
            }
        } catch (\Throwable $e) {
            $this->logger->info('[Panth_XmlSitemap] blog posts (Magefan) failed: ' . $e->getMessage());
        }
    }

    private function getMageplazaUrls(
        AdapterInterface $conn,
        int $storeId,
        string $baseUrl,
        string $changefreq,
        float $priority
    ): \Generator {
        try {
            $postTable = $this->resource->getTableName(self::MAGEPLAZA_POST_TABLE);

            $cols = $conn->describeTable($postTable);
            if (!isset($cols['url_key'])) {
                return;
            }

            $columns = ['url_key'];
            if (isset($cols['updated_at'])) {
                $columns[] = 'updated_at';
            }

            $select = $conn->select()
                ->from($postTable, $columns)
                ->where('url_key IS NOT NULL')
                ->where('url_key != ?', '');
            if (isset($cols['enabled'])) {
                $select->where('enabled = ?', 1);
            }
            if (isset($cols['publish_date'])) {
                $select->where('publish_date IS NULL OR publish_date <= ?', new \Zend_Db_Expr('NOW()'));
            }
            if (isset($cols['store_ids'])) {
                $select->where('FIND_IN_SET(0, store_ids) OR FIND_IN_SET(?, store_ids)', $storeId);
            }

            $route = trim($this->firstConfigValue(self::MAGEPLAZA_ROUTE_PATHS, $storeId) ?: self::MAGEPLAZA_DEFAULT_ROUTE, '/');
            $suffixValue = trim($this->firstConfigValue(self::MAGEPLAZA_SUFFIX_PATHS, $storeId));
            $suffix = $suffixValue !== '' ? '.' . ltrim($suffixValue, '.') : '';

            $stmt = $conn->query($select);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $urlKey = trim((string) ($row['url_key'] ?? ''), '/');
                if ($urlKey === '') {
                    continue;
                }
                $entry = [
                    'loc'        => $baseUrl . $route . '/' . self::MAGEPLAZA_POST_TYPE . '/' . $urlKey . $suffix,
                    'changefreq' => $changefreq,
                    'priority'   => $priority,
                ];
                if (!empty($row['updated_at'])) {
                    $entry['lastmod'] = $this->formatLastmod((string) $row['updated_at']);
                }
                yield $entry;
            }
        } catch (\Throwable $e) {
            $this->logger->info('[Panth_XmlSitemap] blog posts (Mageplaza) failed: ' . $e->getMessage());
        }
    }

    private function firstConfigValue(array $paths, int $storeId): string
    {
        foreach ($paths as $path) {
            $value = (string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function formatLastmod(string $raw): ?string
    {
        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d\TH:i:sP');
        } catch (\Throwable) {
            return null;
        }
    }

    private function getPanthBlogUrls(
        \Magento\Framework\DB\Adapter\AdapterInterface $conn,
        int $storeId,
        string $baseUrl,
        array $config
    ): \Generator {
        $changefreqPost = $config['changefreq'] ?? self::PANTH_CHANGEFREQ_POST;
        $priorityPost = isset($config['priority']) ? (float) $config['priority'] : self::PANTH_PRIORITY_POST;
        $route = $this->getPanthRoute($storeId);

        try {
            $select = $conn->select()
                ->from($this->resource->getTableName(self::PANTH_POST_TABLE), ['url_key', 'updated_at', 'published_at'])
                ->where('status = ?', 'published');
            foreach ($conn->fetchAll($select) as $row) {
                $slug = trim((string) ($row['url_key'] ?? ''), '/');
                if ($slug === '') {
                    continue;
                }
                $lastmod = (string) ($row['updated_at'] ?? '');
                if ($lastmod === '') {
                    $lastmod = (string) ($row['published_at'] ?? '');
                }
                yield [
                    'loc' => $baseUrl . '/' . $route . '/' . $slug,
                    'lastmod' => $lastmod !== '' ? date('c', (int) strtotime($lastmod)) : '',
                    'changefreq' => $changefreqPost,
                    'priority' => $priorityPost,
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->info('[Panth_XmlSitemap] blog posts (Panth) failed: ' . $e->getMessage());
        }

        yield from $this->yieldPanthTaxonomy($conn, self::PANTH_CATEGORY_TABLE, $baseUrl, $route . '/category/', true);
        yield from $this->yieldPanthTaxonomy($conn, self::PANTH_TAG_TABLE, $baseUrl, $route . '/tag/', false);
        yield from $this->yieldPanthTaxonomy($conn, self::PANTH_AUTHOR_TABLE, $baseUrl, $route . '/author/', true);

        yield [
            'loc' => $baseUrl . '/' . $route,
            'changefreq' => self::PANTH_CHANGEFREQ_POST,
            'priority' => self::PANTH_PRIORITY_INDEX,
        ];
    }

    private function yieldPanthTaxonomy(
        \Magento\Framework\DB\Adapter\AdapterInterface $conn,
        string $tableAlias,
        string $baseUrl,
        string $pathPrefix,
        bool $filterActive
    ): \Generator {
        $table = $this->resource->getTableName($tableAlias);
        if (!$conn->isTableExists($table)) {
            return;
        }
        try {
            $select = $conn->select()->from($table, ['url_key', 'updated_at']);
            if ($filterActive) {
                $select->where('is_active = ?', 1);
            }
            foreach ($conn->fetchAll($select) as $row) {
                $slug = trim((string) ($row['url_key'] ?? ''), '/');
                if ($slug === '') {
                    continue;
                }
                yield [
                    'loc' => $baseUrl . '/' . $pathPrefix . $slug,
                    'lastmod' => !empty($row['updated_at'])
                        ? date('c', (int) strtotime((string) $row['updated_at']))
                        : '',
                    'changefreq' => self::PANTH_CHANGEFREQ_TAX,
                    'priority' => self::PANTH_PRIORITY_TAX,
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->info('[Panth_XmlSitemap] blog taxonomy ' . $tableAlias . ' failed: ' . $e->getMessage());
        }
    }

    private function getPanthRoute(int $storeId): string
    {
        $route = trim((string) $this->scopeConfig->getValue(
            self::PANTH_ROUTE_PATH,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        ), '/');

        return $route !== '' ? $route : self::PANTH_DEFAULT_ROUTE;
    }
}
