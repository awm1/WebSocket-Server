<?php declare(strict_types=1);

namespace BabDev\WebSocket\Server\Tests\Connection;

use BabDev\WebSocket\Server\Connection\AttributeStore;
use PHPUnit\Framework\TestCase;

final class AttributeStoreTest extends TestCase
{
    public function testProvidesAllAttributes(): void
    {
        $store = new AttributeStore();

        $this->assertEmpty($store->all(), 'The store should provide an empty array by default.');

        $attributes = [
            'foo' => 'bar',
            'goo' => 'car',
        ];

        $store = new AttributeStore();
        $store->replace($attributes);

        $this->assertSame($attributes, $store->all());
    }

    public function testGetSetAndRemoveAttributes(): void
    {
        $store = new AttributeStore();

        $this->assertNull($store->get('foo'), 'The store provides null by default when an attribute is not stored.');
        $this->assertSame('car', $store->get('foo', 'car'), 'The store returns the given default value when an attribute is not stored.');

        $store->set('foo', 'bar');

        $this->assertSame('bar', $store->get('foo'), "The store provides the attribute's value when stored.");

        $store->remove('foo');

        $this->assertNull($store->get('foo'), 'The store provides null by default when an attribute is not stored.');
    }

    public function testHasAttribute(): void
    {
        $store = new AttributeStore();

        $this->assertFalse($store->has('foo'), 'The store reports an attribute is not stored.');

        $store->set('foo', 'bar');

        $this->assertTrue($store->has('foo'), 'The store reports an attribute is stored.');
    }
}
