<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Sitemap\Contributor;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\XmlSitemap\Api\ContributorInterface;
use Psr\Log\LoggerInterface;

class FaqContributor implements ContributorInterface
{
    private const MODULE           = 'Panth_Faq';
    private const ITEM_TABLE       = 'panth_faq_item';
    private const ITEM_STORE_TABLE = 'panth_faq_item_store';
    private const CATEGORY_TABLE   = 'panth_faq_category';
    private const CATEGORY_STORE_TABLE = 'panth_faq_category_store';
    private const ITEM_VALUE_TABLE = 'panth_faq_item_value';
    private const CATEGORY_VALUE_TABLE = 'panth_faq_category_value';

    private const XML_URL_KEY      = 'panth_faq/general/faq_route';
    private const DEFAULT_BASE     = 'faq';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger,
        private readonly ModuleManager $moduleManager
    ) {
    }

    public function getCode(): string
    {
        return 'faq';
    }

    public function getUrls(int $storeId, array $config = []): \Generator
    {
        if (!$this->moduleManager->isEnabled(self::MODULE)) {
            return;
        }
        try {
            $conn = $this->resource->getConnection();
        } catch (\Throwable $e) {
            $this->logger->info('[Panth_XmlSitemap] faq contributor - db unavailable: ' . $e->getMessage());
            return;
        }

        $itemTable     = $this->resource->getTableName(self::ITEM_TABLE);
        $itemStore     = $this->resource->getTableName(self::ITEM_STORE_TABLE);
        $categoryTable = $this->resource->getTableName(self::CATEGORY_TABLE);

        $hasItems      = $conn->isTableExists($itemTable);
        $hasCategories = $conn->isTableExists($categoryTable);
        if (!$hasItems && !$hasCategories) {
            return;
        }
        $hasItemStore = $hasItems && $conn->isTableExists($itemStore);

        $store   = $this->storeManager->getStore($storeId);
        $baseUrl = rtrim(
            (string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_LINK, $store->isUrlSecure()),
            '/'
        ) . '/';
        $base    = trim((string) ($this->scopeConfig->getValue(self::XML_URL_KEY, ScopeInterface::SCOPE_STORE, $storeId)
            ?: self::DEFAULT_BASE), '/');
        if ($base === '') {
            $base = self::DEFAULT_BASE;
        }

        $changefreq = $config['changefreq'] ?? 'monthly';
        $priority   = isset($config['priority']) ? (float) $config['priority'] : 0.5;

        yield [
            'loc'        => $baseUrl . $base,
            'changefreq' => $changefreq,
            'priority'   => min(1.0, $priority + 0.1),
        ];

        if ($hasCategories) {
            try {
                $cols = $conn->describeTable($categoryTable);
                $columns = ['category_id'];
                if (isset($cols['updated_at'])) {
                    $columns[] = 'updated_at';
                }
                $select = $conn->select()->from(['c' => $categoryTable], $columns);
                $this->applyStoreScope(
                    $conn,
                    $select,
                    'c',
                    'category_id',
                    self::CATEGORY_STORE_TABLE,
                    self::CATEGORY_VALUE_TABLE,
                    $storeId,
                    true
                );
                $stmt = $conn->query($select);
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $urlKey = trim((string) ($row['url_key'] ?? ''));
                    if ($urlKey === '') {
                        continue;
                    }
                    $entry = [
                        'loc'        => $baseUrl . $base . '/category/' . $urlKey,
                        'changefreq' => $changefreq,
                        'priority'   => $priority,
                    ];
                    if (!empty($row['updated_at'])) {
                        $entry['lastmod'] = $this->formatLastmod((string) $row['updated_at']);
                    }
                    yield $entry;
                }
            } catch (\Throwable $e) {
                $this->logger->info('[Panth_XmlSitemap] faq categories failed: ' . $e->getMessage());
            }
        }

        if ($hasItems) {
            try {
                $cols = $conn->describeTable($itemTable);
                $columns = ['item_id'];
                if (isset($cols['updated_at'])) {
                    $columns[] = 'updated_at';
                }
                $select = $conn->select()->from(['i' => $itemTable], $columns);
                $this->applyStoreScope(
                    $conn,
                    $select,
                    'i',
                    'item_id',
                    $hasItemStore ? self::ITEM_STORE_TABLE : '',
                    self::ITEM_VALUE_TABLE,
                    $storeId,
                    isset($cols['is_active'])
                );
                $stmt = $conn->query($select);
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $urlKey = trim((string) ($row['url_key'] ?? ''));
                    if ($urlKey === '') {
                        continue;
                    }
                    $entry = [
                        'loc'        => $baseUrl . $base . '/item/' . $urlKey,
                        'changefreq' => $changefreq,
                        'priority'   => $priority,
                    ];
                    if (!empty($row['updated_at'])) {
                        $entry['lastmod'] = $this->formatLastmod((string) $row['updated_at']);
                    }
                    yield $entry;
                }
            } catch (\Throwable $e) {
                $this->logger->info('[Panth_XmlSitemap] faq items failed: ' . $e->getMessage());
            }
        }
    }

    private function applyStoreScope(
        \Magento\Framework\DB\Adapter\AdapterInterface $conn,
        \Magento\Framework\DB\Select $select,
        string $alias,
        string $key,
        string $storeTableAlias,
        string $valueTableAlias,
        int $storeId,
        bool $hasActive
    ): void {
        $urlKey = $alias . '.url_key';
        $active = $alias . '.is_active';
        $valueTable = $this->resource->getTableName($valueTableAlias);
        if ($storeId > 0 && $conn->isTableExists($valueTable)) {
            $valueCols = $conn->describeTable($valueTable);
            $select->joinLeft(
                ['v' => $valueTable],
                'v.' . $key . ' = ' . $alias . '.' . $key . ' AND v.store_id = ' . $storeId,
                []
            );
            if (isset($valueCols['url_key'])) {
                $urlKey = 'COALESCE(NULLIF(v.url_key, \'\'), ' . $alias . '.url_key)';
            }
            if (isset($valueCols['is_active'])) {
                $active = 'COALESCE(v.is_active, ' . $alias . '.is_active)';
            }
        }
        $select->columns(['url_key' => new \Zend_Db_Expr($urlKey)]);
        $select->where($urlKey . ' IS NOT NULL')->where($urlKey . ' != ?', '');
        if ($hasActive) {
            $select->where($active . ' = ?', 1);
        }

        if ($storeTableAlias === '') {
            return;
        }
        $storeTable = $this->resource->getTableName($storeTableAlias);
        if ($conn->isTableExists($storeTable)) {
            $select->join(
                ['s' => $storeTable],
                's.' . $key . ' = ' . $alias . '.' . $key . ' AND s.store_id IN (0, ' . $storeId . ')',
                []
            )->group($alias . '.' . $key);
        }
    }

    private function formatLastmod(string $raw): ?string
    {
        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d\TH:i:sP');
        } catch (\Throwable) {
            return null;
        }
    }
}
