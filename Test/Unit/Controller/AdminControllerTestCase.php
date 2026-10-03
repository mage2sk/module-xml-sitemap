<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Controller;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Shared wiring for admin controller tests: records redirects and flash messages.
 */
abstract class AdminControllerTestCase extends TestCase
{
    protected array $redirect = [];
    protected array $messages = ['success' => [], 'error' => []];

    protected function context(array $params = [], array $post = []): Context
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(
            static fn(string $key, $default = null) => $params[$key] ?? $default
        );
        $request->method('getPostValue')->willReturn($post);

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function (string $path, array $args = []) use ($redirect) {
            $this->redirect = [$path, $args];
            return $redirect;
        });
        $factory = $this->createStub(RedirectFactory::class);
        $factory->method('create')->willReturn($redirect);

        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addSuccessMessage')->willReturnCallback(function ($m) use ($messages) {
            $this->messages['success'][] = (string) $m;
            return $messages;
        });
        $messages->method('addErrorMessage')->willReturnCallback(function ($m) use ($messages) {
            $this->messages['error'][] = (string) $m;
            return $messages;
        });

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultRedirectFactory')->willReturn($factory);
        $context->method('getMessageManager')->willReturn($messages);
        return $context;
    }
}
