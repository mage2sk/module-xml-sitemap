<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Model\Sitemap\Contributor;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * In-memory stand-ins for the DB adapter so contributors can be exercised without a database.
 */
trait DbStubTrait
{
    /** @var list<string> */
    private array $whereLog = [];

    private function selectStub(): Select
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'join', 'joinInner', 'joinLeft', 'group', 'order', 'limit', 'reset', 'columns'] as $m) {
            $select->method($m)->willReturnSelf();
        }
        $log = &$this->whereLog;
        $select->method('where')->willReturnCallback(function ($cond) use ($select, &$log) {
            $log[] = (string) $cond;
            return $select;
        });
        return $select;
    }

    private function statement(array $rows): \Zend_Db_Statement_Interface
    {
        $stmt = $this->createStub(\Zend_Db_Statement_Interface::class);
        $stmt->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
        return $stmt;
    }

    /**
     * @param list<array> $queryResults rows returned by successive query() calls
     * @param array<string,bool> $tables table existence; unknown tables exist
     * @param array<string,array> $describe describeTable() results per table
     */
    private function connection(
        array $queryResults = [],
        array $tables = [],
        array $describe = [],
        array $fetchAll = [],
        array $fetchOne = []
    ): Mysql {
        $conn = $this->createStub(Mysql::class);
        $conn->method('select')->willReturnCallback(fn() => $this->selectStub());
        $conn->method('isTableExists')->willReturnCallback(
            static fn(string $t) => $tables[$t] ?? true
        );
        $conn->method('describeTable')->willReturnCallback(
            static fn(string $t) => $describe[$t] ?? []
        );
        $statements = array_map(fn(array $rows) => $this->statement($rows), $queryResults);
        $conn->method('query')->willReturnCallback(static function () use (&$statements) {
            $next = array_shift($statements);
            if ($next === null) {
                throw new \RuntimeException('unexpected query');
            }
            return $next;
        });
        $conn->method('fetchAll')->willReturnOnConsecutiveCalls(...($fetchAll ?: [[]]));
        $conn->method('fetchOne')->willReturnCallback(static function ($sql) use ($fetchOne) {
            if (!is_string($sql)) {
                return $fetchOne['__select'] ?? false;
            }
            foreach ($fetchOne as $needle => $value) {
                if (str_contains($sql, (string) $needle)) {
                    return $value;
                }
            }
            return false;
        });
        $conn->method('quote')->willReturnCallback(static fn($v) => "'" . $v . "'");
        $conn->method('quoteIdentifier')->willReturnCallback(static fn($v) => '`' . $v . '`');
        return $conn;
    }

    private function resource(?Mysql $conn, ?\Throwable $error = null): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        if ($error !== null) {
            $resource->method('getConnection')->willThrowException($error);
        } else {
            $resource->method('getConnection')->willReturn($conn);
        }
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    private function storeManager(int $websiteId = 1, int $rootCategoryId = 2): StoreManagerInterface
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturnCallback(
            static fn($type = 'link') => $type === 'media' ? 'https://shop.test/media/' : 'https://shop.test/'
        );
        $store->method('isUrlSecure')->willReturn(true);
        $store->method('getWebsiteId')->willReturn($websiteId);
        $store->method('getRootCategoryId')->willReturn($rootCategoryId);
        $sm = $this->createStub(StoreManagerInterface::class);
        $sm->method('getStore')->willReturn($store);
        return $sm;
    }
}
