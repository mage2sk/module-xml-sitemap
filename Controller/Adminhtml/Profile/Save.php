<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Controller\Adminhtml\Profile;

use Panth\XmlSitemap\Controller\Adminhtml\AbstractAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Backend\App\Action\Context;
use Magento\Store\Model\StoreManagerInterface;

class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_XmlSitemap::profiles';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $data = (array)$this->getRequest()->getPostValue();
        $resultRedirect = $this->resultRedirectFactory->create();
        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $id = (int)($data['profile_id'] ?? 0);

        // Defence-in-depth: the form's StoreViewSource only offers real
        // store views, but a hand-crafted POST could still try to land
        // store_id = 0 ("All Store Views"). Reject it here so the row
        // never reaches the database.
        $storeId = (int)($data['store_id'] ?? 0);
        if ($storeId <= 0) {
            $this->messageManager->addErrorMessage(
                __('Pick a single store view — sitemap profiles are scoped per store view.')
            );
            return $resultRedirect->setPath('*/*/edit', $id > 0 ? ['id' => $id] : []);
        }
        try {
            $this->storeManager->getStore($storeId);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('The selected store view no longer exists.'));
            return $resultRedirect->setPath('*/*/edit', $id > 0 ? ['id' => $id] : []);
        }

        // Handle entity_types - could come as array from checkboxes or comma-separated string
        $entityTypes = $data['entity_types'] ?? 'product,category,cms';
        if (is_array($entityTypes)) {
            $entityTypes = implode(',', $entityTypes);
        }

        $row = [
            'name' => mb_substr((string)($data['name'] ?? ''), 0, 255),
            'store_id' => $storeId,
            'entity_types' => $entityTypes,
            'include_images' => (int)($data['include_images'] ?? 1),
            'include_video' => (int)($data['include_video'] ?? 0),
            'include_hreflang' => (int)($data['include_hreflang'] ?? 0),
            'max_urls_per_file' => (int)($data['max_urls_per_file'] ?? 50000),
            'changefreq_product' => (string)($data['changefreq_product'] ?? 'weekly'),
            'changefreq_category' => (string)($data['changefreq_category'] ?? 'weekly'),
            'changefreq_cms' => (string)($data['changefreq_cms'] ?? 'monthly'),
            'priority_product' => (float)($data['priority_product'] ?? 0.8),
            'priority_category' => (float)($data['priority_category'] ?? 0.6),
            'priority_cms' => (float)($data['priority_cms'] ?? 0.5),
            'priority_homepage' => (float)($data['priority_homepage'] ?? 1.0),
            'exclude_out_of_stock' => (int)($data['exclude_out_of_stock'] ?? 0),
            'exclude_noindex' => (int)($data['exclude_noindex'] ?? 1),
            'custom_links' => (string)($data['custom_links'] ?? ''),
            'output_path' => (string)($data['output_path'] ?? 'sitemap/panth/{store_code}/'),
            'is_active' => (int)($data['is_active'] ?? 1),
            'cron_enabled' => (int)($data['cron_enabled'] ?? 0),
            'cron_schedule' => (string)($data['cron_schedule'] ?? '0 2 * * *'),
            'updated_at' => $this->dateTime->gmtDate(),
        ];

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_sitemap_profile');
            if ($id > 0) {
                $connection->update($table, $row, ['profile_id = ?' => $id]);
            } else {
                $row['created_at'] = $this->dateTime->gmtDate();
                $connection->insert($table, $row);
                $id = (int)$connection->lastInsertId($table);
            }
            $this->messageManager->addSuccessMessage(__('Sitemap profile saved.'));
            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }

        return $resultRedirect->setPath('*/*/');
    }
}
