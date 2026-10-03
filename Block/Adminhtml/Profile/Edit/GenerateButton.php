<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Block\Adminhtml\Profile\Edit;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class GenerateButton implements ButtonProviderInterface
{
    private readonly Escaper $escaper;

    public function __construct(
        private readonly UrlInterface $urlBuilder,
        private readonly RequestInterface $request,
        ?Escaper $escaper = null
    ) {
        $this->escaper = $escaper ?? new Escaper();
    }

    public function getButtonData(): array
    {
        $id = (int)$this->request->getParam('id');
        if ($id === 0) {
            return [];
        }

        return [
            'label' => __('Generate Now'),
            'class' => 'action-secondary',
            'on_click' => sprintf(
                "confirmSetLocation('%s', '%s', {\"data\": {}})",
                $this->escaper->escapeJs((string) __('This will trigger sitemap generation for this profile. Continue?')),
                $this->urlBuilder->getUrl('panth_xml_sitemap/profile/generate', ['id' => $id])
            ),
            'sort_order' => 30,
        ];
    }
}
