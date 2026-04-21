<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Controller\Adminhtml\Profile;

use Panth\XmlSitemap\Controller\Adminhtml\AbstractAction;
use Magento\Framework\View\Result\PageFactory;
use Magento\Backend\App\Action\Context;

class Index extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Panth_XmlSitemap::profiles';

    public function __construct(Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_XmlSitemap::profiles');
        $page->getConfig()->getTitle()->prepend(__('XML Sitemap'));
        return $page;
    }
}
