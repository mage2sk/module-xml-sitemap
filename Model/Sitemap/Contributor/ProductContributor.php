<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Sitemap\Contributor;

use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Panth\XmlSitemap\Api\ContributorInterface;
use Panth\XmlSitemap\Helper\Config;

class ProductContributor implements ContributorInterface
{
    private ?array $imageAttributeIds = null;

    private array $productAttributeIds = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly MediaConfig $mediaConfig
    ) {
    }

    public function getCode(): string
    {
        return 'product';
    }

    public function getUrls(int $storeId, array $config = []): \Generator
    {
        $store   = $this->storeManager->getStore($storeId);
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';

        if ($this->config->isSitemapHomepageOptimization($storeId)) {
            $defaultChangefreq = $config['changefreq'] ?? 'daily';
            $homepagePriority  = isset($config['priority_homepage'])
                ? (float) $config['priority_homepage']
                : 1.0;
            yield [
                'loc'        => rtrim($baseUrl, '/') . '/',
                'changefreq' => $defaultChangefreq,
                'priority'   => $homepagePriority,
            ];
        }

        $conn = $this->resource->getConnection();

        $pdo = $conn->getConnection();
        $urlTable = $this->resource->getTableName('url_rewrite');

        if ($pdo instanceof \PDO) {
            try {
                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            } catch (\Throwable) {
            }
        }

        $stockTable    = $this->resource->getTableName('cataloginventory_stock_status');
        $resolvedTable = $this->resource->getTableName('panth_seo_resolved');
        $entityTable   = $this->resource->getTableName('catalog_product_entity');
        $websiteTable  = $this->resource->getTableName('catalog_product_website');
        $intTable      = $this->resource->getTableName('catalog_product_entity_int');

        $excludeOos     = isset($config['exclude_out_of_stock'])
            ? (bool) $config['exclude_out_of_stock']
            : $this->config->sitemapExcludeOutOfStock($storeId);
        $excludeNoindex = isset($config['exclude_noindex'])
            ? (bool) $config['exclude_noindex']
            : $this->config->sitemapExcludeNoindex($storeId);
        $includeImages  = isset($config['include_images'])
            ? (bool) $config['include_images']
            : $this->config->sitemapIncludeImages($storeId);
        $imageSource    = $this->config->getSitemapProductImageSource($storeId);

        $imageAttributeId = $includeImages ? $this->resolveImageAttributeId($imageSource) : 0;
        $mediaBaseUrl     = $includeImages ? $this->getMediaBaseUrl($store) : '';

        $websiteId    = (int) $store->getWebsiteId();
        $statusAttrId = $this->resolveProductAttributeId('status');
        $visAttrId    = $this->resolveProductAttributeId('visibility');

        $selects = 'ur.request_path, ur.metadata, ur.entity_id, cpe.updated_at AS product_updated_at';

        $joins = sprintf(
            ' INNER JOIN %s AS cpe ON cpe.entity_id = ur.entity_id',
            $conn->quoteIdentifier($entityTable)
        );
        $joins .= sprintf(
            ' INNER JOIN %s AS pw ON pw.product_id = ur.entity_id AND pw.website_id = %d',
            $conn->quoteIdentifier($websiteTable),
            $websiteId
        );
        $wheres = '';

        if ($statusAttrId > 0) {
            $joins .= sprintf(
                ' LEFT JOIN %1$s AS status_admin'
                . ' ON status_admin.entity_id = ur.entity_id'
                . ' AND status_admin.attribute_id = %2$d'
                . ' AND status_admin.store_id = 0'
                . ' LEFT JOIN %1$s AS status_store'
                . ' ON status_store.entity_id = ur.entity_id'
                . ' AND status_store.attribute_id = %2$d'
                . ' AND status_store.store_id = %3$d',
                $conn->quoteIdentifier($intTable),
                $statusAttrId,
                $storeId
            );
            $wheres .= ' AND COALESCE(status_store.value, status_admin.value) = 1';
        }

        if ($visAttrId > 0) {
            $joins .= sprintf(
                ' LEFT JOIN %1$s AS vis_admin'
                . ' ON vis_admin.entity_id = ur.entity_id'
                . ' AND vis_admin.attribute_id = %2$d'
                . ' AND vis_admin.store_id = 0'
                . ' LEFT JOIN %1$s AS vis_store'
                . ' ON vis_store.entity_id = ur.entity_id'
                . ' AND vis_store.attribute_id = %2$d'
                . ' AND vis_store.store_id = %3$d',
                $conn->quoteIdentifier($intTable),
                $visAttrId,
                $storeId
            );

            $wheres .= ' AND COALESCE(vis_store.value, vis_admin.value) IN (2, 4)';
        }

        if ($excludeOos) {
            $joins .= sprintf(
                ' INNER JOIN %s AS stock ON stock.product_id = ur.entity_id AND stock.website_id = 0 AND stock.stock_status = 1',
                $conn->quoteIdentifier($stockTable)
            );
        }

        if ($excludeNoindex) {
            $joins .= sprintf(
                ' LEFT JOIN %s AS seo ON seo.entity_type = \'product\' AND seo.entity_id = ur.entity_id AND seo.store_id IN (0, %d)',
                $conn->quoteIdentifier($resolvedTable),
                $storeId
            );
            $wheres .= ' AND (seo.robots IS NULL OR seo.robots NOT LIKE \'%%noindex%%\')';
        }

        if ($includeImages && $imageAttributeId > 0) {
            $varcharTable = $this->resource->getTableName('catalog_product_entity_varchar');
            $selects .= ', COALESCE(img_store.value, img_default.value) AS product_image';
            $joins .= sprintf(
                ' LEFT JOIN %1$s AS img_default'
                . ' ON img_default.entity_id = ur.entity_id'
                . ' AND img_default.attribute_id = %2$d'
                . ' AND img_default.store_id = 0'
                . ' LEFT JOIN %1$s AS img_store'
                . ' ON img_store.entity_id = ur.entity_id'
                . ' AND img_store.attribute_id = %2$d'
                . ' AND img_store.store_id = %3$d',
                $conn->quoteIdentifier($varcharTable),
                $imageAttributeId,
                $storeId
            );
        }

        $sql = sprintf(
            'SELECT %s FROM %s AS ur'
            . '%s'
            . ' WHERE ur.entity_type = %s AND ur.store_id = %d AND ur.redirect_type = 0 AND ur.is_autogenerated = 1'
            . '%s',
            $selects,
            $conn->quoteIdentifier($urlTable),
            $joins,
            $conn->quote('product'),
            $storeId,
            $wheres
        );
        $stmt = $pdo instanceof \PDO ? $pdo->query($sql) : $conn->query($sql);
        if (!$stmt) {
            return;
        }

        try {
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $path = (string) ($row['request_path'] ?? '');
                if ($path === '') {
                    continue;
                }

                $entry = [
                    'loc'        => $baseUrl . ltrim($path, '/'),
                    'changefreq' => $config['changefreq'] ?? 'weekly',
                    'priority'   => isset($config['priority']) ? (float) $config['priority'] : 0.8,
                ];

                $updatedAt = (string) ($row['product_updated_at'] ?? '');
                if ($updatedAt !== '') {
                    try {
                        $entry['lastmod'] = (new \DateTimeImmutable($updatedAt))
                            ->format('Y-m-d\TH:i:sP');
                    } catch (\Throwable) {
                    }
                }

                if ($this->config->isSitemapHomepageOptimization($storeId) && $this->isHomepage($path)) {
                    $entry['changefreq'] = 'daily';
                    $entry['priority']   = isset($config['priority_homepage'])
                        ? (float) $config['priority_homepage']
                        : 1.0;
                }

                if ($includeImages && $imageAttributeId > 0) {
                    $imagePath = $this->sanitizeImageValue((string) ($row['product_image'] ?? ''));
                    if ($imagePath !== '') {
                        $entry['images'] = [
                            ['loc' => $mediaBaseUrl . $imagePath],
                        ];
                    }
                }

                yield $entry;
            }
        } finally {
            if ($pdo instanceof \PDO) {
                try {
                    $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                } catch (\Throwable) {
                }
            }
        }
    }

    private function isHomepage(string $path): bool
    {
        $normalised = trim(strtolower($path), '/');
        return $normalised === '' || $normalised === 'home';
    }

    private function resolveImageAttributeId(string $imageSource): int
    {
        if ($this->imageAttributeIds === null) {
            $this->imageAttributeIds = [];
            $conn = $this->resource->getConnection();
            $eavTable = $this->resource->getTableName('eav_attribute');
            $entityTypeTable = $this->resource->getTableName('eav_entity_type');

            $sql = sprintf(
                'SELECT ea.attribute_code, ea.attribute_id FROM %s AS ea'
                . ' INNER JOIN %s AS et ON et.entity_type_id = ea.entity_type_id AND et.entity_type_code = %s'
                . ' WHERE ea.attribute_code IN (%s, %s, %s)',
                $conn->quoteIdentifier($eavTable),
                $conn->quoteIdentifier($entityTypeTable),
                $conn->quote('catalog_product'),
                $conn->quote('image'),
                $conn->quote('small_image'),
                $conn->quote('thumbnail')
            );

            $rows = $conn->fetchAll($sql);
            foreach ($rows as $row) {
                $this->imageAttributeIds[(string) $row['attribute_code']] = (int) $row['attribute_id'];
            }
        }

        $attributeCode = match ($imageSource) {
            'small_image' => 'small_image',
            'thumbnail'   => 'thumbnail',
            default       => 'image',
        };

        return $this->imageAttributeIds[$attributeCode] ?? 0;
    }

    private function getMediaBaseUrl(mixed $store): string
    {
        $baseMediaUrl = rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/');
        return $baseMediaUrl . '/catalog/product';
    }

    private function resolveProductAttributeId(string $code): int
    {
        if (array_key_exists($code, $this->productAttributeIds)) {
            return $this->productAttributeIds[$code];
        }
        $conn = $this->resource->getConnection();
        $eavTable = $this->resource->getTableName('eav_attribute');
        $entityTypeTable = $this->resource->getTableName('eav_entity_type');
        $sql = sprintf(
            'SELECT ea.attribute_id FROM %s AS ea'
            . ' INNER JOIN %s AS et ON et.entity_type_id = ea.entity_type_id'
            . ' WHERE et.entity_type_code = %s AND ea.attribute_code = %s LIMIT 1',
            $conn->quoteIdentifier($eavTable),
            $conn->quoteIdentifier($entityTypeTable),
            $conn->quote('catalog_product'),
            $conn->quote($code)
        );
        return $this->productAttributeIds[$code] = (int) $conn->fetchOne($sql);
    }

    private function sanitizeImageValue(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === 'no_selection') {
            return '';
        }
        return str_starts_with($value, '/') ? $value : '/' . $value;
    }
}
