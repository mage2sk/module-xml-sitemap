<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Block\Adminhtml;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class GenericDeleteButton implements ButtonProviderInterface
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

        $deleteUrl = $this->getDeleteUrl($id);

        return [
            'label' => __('Delete'),
            'class' => 'delete',
            'on_click' => 'deleteConfirm(\''
                . $this->escaper->escapeJs((string) __('Are you sure you want to delete this item?'))
                . '\', \''
                . $deleteUrl . '\', {"data": {}})',
            'sort_order' => 20,
        ];
    }

    private function getDeleteUrl(int $id): string
    {
        $routeName = $this->request->getRouteName();
        $controllerName = $this->request->getControllerName();

        return $this->urlBuilder->getUrl(
            $routeName . '/' . $controllerName . '/delete',
            ['id' => $id]
        );
    }
}
