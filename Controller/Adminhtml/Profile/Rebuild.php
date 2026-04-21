<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Controller\Adminhtml\Profile;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Panth\XmlSitemap\Cron\SitemapRebuild;
use Magento\Backend\App\Action\Context;

class Rebuild extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_XmlSitemap::profiles';

    public function __construct(Context $context, private readonly SitemapRebuild $rebuild)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        try {
            $this->rebuild->execute();
            $this->messageManager->addSuccessMessage(__('Sitemaps rebuilt.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $resultRedirect->setPath('*/*/');
    }
}
