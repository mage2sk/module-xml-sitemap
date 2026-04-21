<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Ui\Component\Listing\Columns\Column;

class ProfileActions extends Column
{
    public const URL_PATH_EDIT = 'panth_xml_sitemap/profile/edit';
    public const URL_PATH_DELETE = 'panth_xml_sitemap/profile/delete';
    public const URL_PATH_GENERATE = 'panth_xml_sitemap/profile/generate';

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly StoreManagerInterface $storeManager,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        $name = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $id = $item['profile_id'] ?? null;
            if ($id === null) {
                continue;
            }

            // Build the view sitemap URL. Only surface the action when the
            // profile has actually been generated; otherwise the link would
            // 404. Respect the profile's `output_path` template.
            $fileCount = (int) ($item['file_count'] ?? 0);
            if ($fileCount > 0) {
                $sitemapUrl = $this->getSitemapUrl($item);
                if ($sitemapUrl !== '') {
                    $item[$name]['view'] = [
                        'href' => $sitemapUrl,
                        'label' => (string) __('View Sitemap'),
                        'target' => '_blank',
                    ];
                }
            }

            $item[$name]['edit'] = [
                'href' => $this->urlBuilder->getUrl(self::URL_PATH_EDIT, ['id' => $id]),
                'label' => (string) __('Edit'),
            ];
            $item[$name]['generate'] = [
                'href' => $this->urlBuilder->getUrl(self::URL_PATH_GENERATE, ['id' => $id]),
                'label' => (string) __('Generate Now'),
                'confirm' => [
                    'title' => (string) __('Generate Sitemap'),
                    'message' => (string) __('This will trigger sitemap generation for this profile. Continue?'),
                ],
            ];
            $item[$name]['delete'] = [
                'href' => $this->urlBuilder->getUrl(self::URL_PATH_DELETE, ['id' => $id]),
                'label' => (string) __('Delete'),
                'confirm' => [
                    'title' => (string) __('Delete Profile'),
                    'message' => (string) __('Are you sure you want to delete this sitemap profile?'),
                ],
            ];
        }
        return $dataSource;
    }

    private function getSitemapUrl(array $item): string
    {
        try {
            $storeId = (int) ($item['store_id'] ?? 1);
            if ($storeId === 0) {
                $storeId = (int) $this->storeManager->getDefaultStoreView()?->getId() ?: 1;
            }
            $store     = $this->storeManager->getStore($storeId);
            $storeCode = (string) $store->getCode();
            $baseUrl   = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/');
            $profileId = (int) ($item['profile_id'] ?? 0);

            $outputPath = trim((string) ($item['output_path'] ?? ''));
            if ($outputPath !== '') {
                $relDir = rtrim(strtr($outputPath, ['{store_code}' => $storeCode]), '/');
            } else {
                $relDir = 'sitemap/panth/' . $storeCode . '/profile-' . $profileId;
            }

            return $baseUrl . '/' . ltrim($relDir, '/') . '/sitemap_index.xml';
        } catch (\Throwable) {
            return '';
        }
    }
}
