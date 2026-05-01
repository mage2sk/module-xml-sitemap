<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Sitemap\Contributor;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\XmlSitemap\Api\ContributorInterface;
use Psr\Log\LoggerInterface;

/**
 * Sitemap contributor for `Panth_Faq`.
 *
 * **Optional integration** — never references a class from
 * `Panth_Faq`. Behaviour depends entirely on the presence of the
 * source module's tables:
 *
 *   - `panth_faq_item`              individual question/answer rows
 *   - `panth_faq_item_store`        store-scope junction (item_id, store_id)
 *   - `panth_faq_category`          category landing pages
 *
 * If neither item table nor category table exists the contributor
 * yields zero URLs. If only one is present, only that side
 * contributes.
 *
 * URL patterns (matches Panth_Faq Controller/Router.php):
 *   - /{base}                          → main FAQ page
 *   - /{base}/category/{url_key}       → category landing
 *   - /{base}/item/{url_key}           → individual FAQ item with a slug
 *
 * The base segment defaults to `faq` but the source module exposes
 * `panth_faq/general/faq_route` so a merchant who renamed it (e.g.
 * to `help` or `faqs`) gets the right URLs in the sitemap.
 */
class FaqContributor implements ContributorInterface
{
    private const ITEM_TABLE       = 'panth_faq_item';
    private const ITEM_STORE_TABLE = 'panth_faq_item_store';
    private const CATEGORY_TABLE   = 'panth_faq_category';
    /**
     * Source module stores the front-facing slug under this path
     * (Helper\Data::XML_PATH_FAQ_ROUTE in module-faq). v1.0.10
     * mistakenly read `panth_faq/general/url_key`, which was never
     * populated, so the contributor always emitted /faq/* even when
     * the merchant renamed the route.
     */
    private const XML_URL_KEY      = 'panth_faq/general/faq_route';
    private const DEFAULT_BASE     = 'faq';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getCode(): string
    {
        return 'faq';
    }

    public function getUrls(int $storeId, array $config = []): \Generator
    {
        try {
            $conn = $this->resource->getConnection();
        } catch (\Throwable $e) {
            $this->logger->info('[Panth_XmlSitemap] faq contributor — db unavailable: ' . $e->getMessage());
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
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';
        $base    = trim((string) ($this->scopeConfig->getValue(self::XML_URL_KEY, ScopeInterface::SCOPE_STORE, $storeId)
            ?: self::DEFAULT_BASE), '/');
        if ($base === '') {
            $base = self::DEFAULT_BASE;
        }

        $changefreq = $config['changefreq'] ?? 'monthly';
        $priority   = isset($config['priority']) ? (float) $config['priority'] : 0.5;

        // Main FAQ landing page.
        yield [
            'loc'        => $baseUrl . $base,
            'changefreq' => $changefreq,
            'priority'   => min(1.0, $priority + 0.1),
        ];

        // FAQ category landing pages.
        if ($hasCategories) {
            try {
                $cols = $conn->describeTable($categoryTable);
                $columns = ['url_key'];
                if (isset($cols['updated_at'])) {
                    $columns[] = 'updated_at';
                }
                $select = $conn->select()
                    ->from($categoryTable, $columns)
                    ->where('is_active = ?', 1)
                    ->where('url_key IS NOT NULL')
                    ->where('url_key != ?', '');
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

        // Individual FAQ items.
        if ($hasItems) {
            try {
                $cols = $conn->describeTable($itemTable);
                $columns = ['item_id', 'url_key'];
                if (isset($cols['updated_at'])) {
                    $columns[] = 'updated_at';
                }
                $select = $conn->select()
                    ->from(['i' => $itemTable], $columns)
                    ->where('i.url_key IS NOT NULL')
                    ->where('i.url_key != ?', '');
                if (isset($cols['is_active'])) {
                    $select->where('i.is_active = ?', 1);
                }
                if ($hasItemStore) {
                    // Restrict to items mapped to the current store
                    // OR the all-stores scope (store_id = 0). Use a
                    // semi-join so duplicate rows from a multi-store
                    // mapping don't get yielded twice.
                    $select->join(
                        ['s' => $itemStore],
                        's.item_id = i.item_id AND s.store_id IN (0, ' . (int) $storeId . ')',
                        []
                    )->group('i.item_id');
                }
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

    private function formatLastmod(string $raw): ?string
    {
        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d\TH:i:sP');
        } catch (\Throwable) {
            return null;
        }
    }
}
