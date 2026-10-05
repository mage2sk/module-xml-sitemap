<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Setup;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\XmlSitemap\Setup\Patch\Data\RemoveDynamicSitemapUrlRewrite;
use PHPUnit\Framework\TestCase;

class RemoveDynamicSitemapUrlRewriteTest extends TestCase
{
    private function patch(AdapterInterface $conn): RemoveDynamicSitemapUrlRewrite
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);
        return new RemoveDynamicSitemapUrlRewrite($resource);
    }

    public function testDeletesOnlyTheModulesRewriteRowsAndIsRepeatable(): void
    {
        $remaining = 3;
        $conn = $this->createMock(AdapterInterface::class);
        $conn->method('isTableExists')->with('url_rewrite')->willReturn(true);
        $conn->expects($this->exactly(2))->method('delete')
            ->with('url_rewrite', [
                'request_path = ?' => 'panth-sitemap.xml',
                'target_path = ?' => 'xml_sitemap/sitemap/index',
            ])
            ->willReturnCallback(static function () use (&$remaining): int {
                $deleted = $remaining;
                $remaining = 0;
                return $deleted;
            });

        $patch = $this->patch($conn);
        $this->assertSame($patch, $patch->apply());
        $this->assertSame($patch, $patch->apply());
        $this->assertSame(0, $remaining);
    }

    public function testSkipsWhenUrlRewriteTableIsMissing(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->method('isTableExists')->willReturn(false);
        $conn->expects($this->never())->method('delete');
        $this->patch($conn)->apply();
    }

    public function testHasNoDependenciesOrAliases(): void
    {
        $this->assertSame([], RemoveDynamicSitemapUrlRewrite::getDependencies());
        $this->assertSame([], $this->patch($this->createStub(AdapterInterface::class))->getAliases());
    }
}
