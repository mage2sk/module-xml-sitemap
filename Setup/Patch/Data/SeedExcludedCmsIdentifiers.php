<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

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
