<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    public const XML_SITEMAP_ENABLED               = 'panth_xml_sitemap/general/enabled';
    public const XML_SITEMAP_HOMEPAGE_OPTIMIZATION = 'panth_xml_sitemap/general/homepage_optimization';

    public const XML_SITEMAP_SHARD_SIZE      = 'panth_xml_sitemap/generation/shard_size';
    public const XML_SITEMAP_GZIP            = 'panth_xml_sitemap/generation/gzip';
    public const XML_SITEMAP_XSL_ENABLED     = 'panth_xml_sitemap/generation/xsl_enabled';
    public const XML_SITEMAP_EXCLUDE_OOS     = 'panth_xml_sitemap/generation/exclude_out_of_stock';
    public const XML_SITEMAP_INDEX_FILENAME  = 'panth_xml_sitemap/generation/index_filename';
    public const XML_SITEMAP_EXCLUDE_NOINDEX = 'panth_xml_sitemap/generation/exclude_noindex';

    public const XML_SITEMAP_INCLUDE_HREFLANG = 'panth_xml_sitemap/hreflang/include_hreflang';

    public const XML_SITEMAP_INCLUDE_IMAGES       = 'panth_xml_sitemap/media/include_images';
    public const XML_SITEMAP_PRODUCT_IMAGE_SOURCE = 'panth_xml_sitemap/media/product_image_source';
    public const XML_SITEMAP_INCLUDE_VIDEO        = 'panth_xml_sitemap/media/include_video';

    public const XML_SITEMAP_PING_GOOGLE = 'panth_xml_sitemap/ping/ping_google';
    public const XML_SITEMAP_PING_BING   = 'panth_xml_sitemap/ping/ping_bing';

    public const XML_SITEMAP_ADDITIONAL                = 'panth_xml_sitemap/additional/additional_links';
    public const XML_SITEMAP_ADDITIONAL_LINKS_FREQ     = 'panth_xml_sitemap/additional/additional_links_changefreq';
    public const XML_SITEMAP_ADDITIONAL_LINKS_PRIORITY = 'panth_xml_sitemap/additional/additional_links_priority';

    public function __construct(
        Context $context,
        private readonly ScopeConfigInterface $scopeConfigDirect
    ) {
        parent::__construct($context);
    }

    public function isSitemapEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_ENABLED, $storeId);
    }

    public function getSitemapShardSize(?int $storeId = null): int
    {
        return max(1000, (int) ($this->value(self::XML_SITEMAP_SHARD_SIZE, $storeId) ?? 45000));
    }

    public function sitemapGzip(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_GZIP, $storeId);
    }

    public function sitemapIncludeImages(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_INCLUDE_IMAGES, $storeId);
    }

    public function sitemapIncludeHreflang(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_INCLUDE_HREFLANG, $storeId);
    }

    public function sitemapIncludeVideo(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_INCLUDE_VIDEO, $storeId);
    }

    public function sitemapExcludeOutOfStock(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_EXCLUDE_OOS, $storeId);
    }

    public function sitemapExcludeNoindex(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_EXCLUDE_NOINDEX, $storeId);
    }

    public function getSitemapAdditionalLinks(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_SITEMAP_ADDITIONAL, $storeId) ?? '');
    }

    public function isSitemapXslEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_XSL_ENABLED, $storeId);
    }

    public function isSitemapHomepageOptimization(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_HOMEPAGE_OPTIMIZATION, $storeId);
    }

    public function getSitemapProductImageSource(?int $storeId = null): string
    {
        $value = (string) ($this->value(self::XML_SITEMAP_PRODUCT_IMAGE_SOURCE, $storeId) ?? 'base_image');
        $allowed = ['base_image', 'small_image', 'thumbnail'];
        return in_array($value, $allowed, true) ? $value : 'base_image';
    }

    public function isSitemapPingGoogleEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_PING_GOOGLE, $storeId);
    }

    public function isSitemapPingBingEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_PING_BING, $storeId);
    }

    public function getAdditionalLinksChangefreq(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_SITEMAP_ADDITIONAL_LINKS_FREQ, $storeId) ?? 'weekly');
    }

    public function getAdditionalLinksPriority(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_SITEMAP_ADDITIONAL_LINKS_PRIORITY, $storeId) ?? '0.5');
    }

    private function flag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfigDirect->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getSitemapIndexFilename(?int $storeId = null): string
    {
        $name = basename(trim((string) ($this->value(self::XML_SITEMAP_INDEX_FILENAME, $storeId) ?? '')));

        return $name !== '' ? $name : 'sitemap.xml';
    }

    private function value(string $path, ?int $storeId): mixed
    {
        return $this->scopeConfigDirect->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
