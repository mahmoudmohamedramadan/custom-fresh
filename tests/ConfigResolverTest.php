<?php

namespace Ramadan\CustomFresh\Tests;

use Ramadan\CustomFresh\Support\ConfigResolver;

class ConfigResolverTest extends TestCase
{
    public function test_it_returns_the_global_value_when_no_connection_is_set()
    {
        config()->set('custom-fresh.always_keep', ['users']);

        $this->assertSame(['users'], (new ConfigResolver)->get('always_keep', []));
    }

    public function test_it_appends_list_overrides_for_the_connection()
    {
        config()->set('custom-fresh.always_keep', ['users']);
        config()->set('custom-fresh.connections.tenant.always_keep', ['tenant_settings']);

        $resolver = new ConfigResolver('tenant');

        $this->assertSame(['users', 'tenant_settings'], $resolver->get('always_keep', []));
    }

    public function test_it_merges_associative_overrides_with_connection_keys_winning()
    {
        config()->set('custom-fresh.table_seeders', [
            'posts' => 'GlobalPostSeeder',
            'tags'  => 'TagSeeder',
        ]);
        config()->set('custom-fresh.connections.tenant.table_seeders', [
            'posts' => 'TenantPostSeeder',
        ]);

        $resolver = new ConfigResolver('tenant');

        $this->assertSame([
            'posts' => 'TenantPostSeeder',
            'tags'  => 'TagSeeder',
        ], $resolver->get('table_seeders', []));
    }

    public function test_it_replaces_scalar_values_from_the_connection()
    {
        config()->set('custom-fresh.replace_migrate_fresh', false);
        config()->set('custom-fresh.connections.tenant.replace_migrate_fresh', true);

        $this->assertTrue((new ConfigResolver('tenant'))->get('replace_migrate_fresh', false));
        $this->assertFalse((new ConfigResolver('testing'))->get('replace_migrate_fresh', false));
    }
}
