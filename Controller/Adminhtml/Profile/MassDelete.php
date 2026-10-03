<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Controller\Adminhtml\Profile;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Ui\Component\MassAction\Filter;
use Panth\XmlSitemap\Controller\Adminhtml\AbstractAction;
use Panth\XmlSitemap\Model\ResourceModel\Profile\CollectionFactory;

class MassDelete extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_XmlSitemap::profiles_save';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        try {
            $ids = array_map('intval', $this->filter->getCollection($this->collectionFactory->create())->getAllIds());
            if ($ids !== []) {
                $this->resource->getConnection()->delete(
                    $this->resource->getTableName('panth_seo_sitemap_profile'),
                    ['profile_id IN (?)' => $ids]
                );
            }
            $this->messageManager->addSuccessMessage(__('%1 sitemap profile(s) deleted.', count($ids)));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $resultRedirect->setPath('*/*/');
    }
}
