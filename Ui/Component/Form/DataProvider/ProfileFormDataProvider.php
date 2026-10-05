<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Ui\Component\Form\DataProvider;

use Magento\Ui\DataProvider\AbstractDataProvider;
use Panth\XmlSitemap\Helper\Config;
use Panth\XmlSitemap\Helper\PathResolver;
use Panth\XmlSitemap\Model\ResourceModel\Profile\CollectionFactory;
use Panth\XmlSitemap\Setup\Patch\Data\SeedExcludedCmsIdentifiers;

class ProfileFormDataProvider extends AbstractDataProvider
{
    private ?array $loadedData = null;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly Config $config,
        private readonly PathResolver $pathResolver,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }

        $this->loadedData = [];
        $items = $this->collection->getItems();

        foreach ($items as $item) {
            $itemData = $item->getData();

            if (isset($itemData['entity_types']) && is_string($itemData['entity_types'])) {
                $itemData['entity_types'] = explode(',', $itemData['entity_types']);
            }
            $itemData['sitemap_filename'] = $this->pathResolver->resolveFilename(
                isset($itemData['sitemap_filename']) ? (string) $itemData['sitemap_filename'] : null,
                $this->config->getSitemapIndexFilename((int) ($itemData['store_id'] ?? 0))
            );
            $this->loadedData[$item->getId()] = $itemData;
        }

        if (empty($this->loadedData)) {
            $this->loadedData[''] = [
                'is_active' => '1',
                'store_id' => '0',
                'entity_types' => ['product', 'category', 'cms'],
                'include_images' => '1',
                'include_video' => '0',
                'include_hreflang' => '0',
                'max_urls_per_file' => '50000',
                'changefreq_product' => 'weekly',
                'changefreq_category' => 'weekly',
                'changefreq_cms' => 'monthly',
                'priority_product' => '0.8',
                'priority_category' => '0.6',
                'priority_cms' => '0.5',
                'priority_homepage' => '1.0',
                'exclude_out_of_stock' => '0',
                'exclude_noindex' => '1',
                'excluded_cms_identifiers' => SeedExcludedCmsIdentifiers::DEFAULT_IDENTIFIERS,
                'output_path' => PathResolver::DEFAULT_PROFILE_PATH,
                'sitemap_filename' => PathResolver::DEFAULT_FILENAME,
                'cron_enabled' => '0',
                'cron_schedule' => '0 2 * * *',
            ];
        }

        return $this->loadedData;
    }
}
