<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Profile extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('panth_seo_sitemap_profile', 'profile_id');
    }
}
