<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Block\Adminhtml\Profile\Edit;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * "View Sitemap" toolbar button — opens the generated sitemap_index.xml
 * in a new tab. Only rendered when the profile has been generated at
 * least once (file_count > 0) so the link points at real files.
 *
 * Honours the profile's output_path column (with `{store_code}` substitution)
 * so custom output locations work too.
 */
class ViewSitemapButton implements ButtonProviderInterface
{
    public function __construct(
        private readonly UrlInterface $urlBuilder,
        private readonly RequestInterface $request,
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getButtonData(): array
    {
        $id = (int) $this->request->getParam('id');
        if ($id === 0) {
            return [];
        }

        $profile = $this->registry->registry('panth_seo_sitemap_profile');
        if (!is_array($profile) || empty($profile)) {
            return [];
        }

        if ((int) ($profile['file_count'] ?? 0) === 0) {
            return [];
        }

        $url = $this->buildSitemapUrl($profile);
        if ($url === '') {
            return [];
        }

        return [
            'label'      => __('View Sitemap'),
            'class'      => 'action-secondary',
            'on_click'   => sprintf("window.open('%s', '_blank')", $url),
            'sort_order' => 20,
        ];
    }

    /** @param array<string,mixed> $profile */
    private function buildSitemapUrl(array $profile): string
    {
        try {
            $storeId = (int) ($profile['store_id'] ?? 0);
            if ($storeId === 0) {
                $storeId = (int) $this->storeManager->getDefaultStoreView()?->getId() ?: 1;
            }
            $store     = $this->storeManager->getStore($storeId);
            $storeCode = (string) $store->getCode();
            $baseUrl   = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/');

            $outputPath = trim((string) ($profile['output_path'] ?? ''));
            if ($outputPath !== '') {
                $relDir = rtrim(strtr($outputPath, ['{store_code}' => $storeCode]), '/');
            } else {
                $relDir = 'sitemap/panth/' . $storeCode . '/profile-' . (int) ($profile['profile_id'] ?? 0);
            }

            return $baseUrl . '/' . ltrim($relDir, '/') . '/sitemap_index.xml';
        } catch (\Throwable) {
            return '';
        }
    }
}
