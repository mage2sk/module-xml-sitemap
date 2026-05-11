<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Seed the `excluded_cms_identifiers` column on every existing sitemap profile
 * with the four built-in low-value identifiers so new and upgraded installs
 * ship a clean CMS shard on day one, regardless of whether `exclude_noindex`
 * is on or whether `panth_seo_resolved` has been populated yet.
 *
 * Idempotent — only updates rows where the column is NULL or empty, so a
 * merchant who has customised the list (or intentionally emptied it) keeps
 * their value.
 */
class SeedExcludedCmsIdentifiers implements DataPatchInterface
{
    public const DEFAULT_IDENTIFIERS = "home\nenable-cookies\nprivacy-policy-cookie-restriction-mode\nno-route";

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public static function getDependencies(): array
    {
        return [
            AddDefaultProfile::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_sitemap_profile');

        if (!$conn->isTableExists($table)) {
            return $this;
        }

        // Skip when the column has not yet been added (declarative schema may
        // run after data patches in odd setup:upgrade orderings on older
        // Magento releases). The next setup:upgrade run will reapply.
        $columns = $conn->describeTable($table);
        if (!isset($columns['excluded_cms_identifiers'])) {
            return $this;
        }

        $conn->update(
            $table,
            ['excluded_cms_identifiers' => self::DEFAULT_IDENTIFIERS],
            "excluded_cms_identifiers IS NULL OR excluded_cms_identifiers = ''"
        );

        return $this;
    }
}
