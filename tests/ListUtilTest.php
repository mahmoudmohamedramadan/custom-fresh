<?php

namespace Ramadan\CustomFresh\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Ramadan\CustomFresh\Support\ListUtil;

class ListUtilTest extends PHPUnitTestCase
{
    public function test_it_splits_and_trims_comma_separated_values()
    {
        $this->assertSame(['users', 'posts'], ListUtil::split(' users, posts , '));
        $this->assertSame([], ListUtil::split(''));
    }

    public function test_it_detects_glob_patterns()
    {
        $this->assertTrue(ListUtil::isGlob('oauth_*'));
        $this->assertTrue(ListUtil::isGlob('user?'));
        $this->assertTrue(ListUtil::isGlob('cache_[ab]'));
        $this->assertFalse(ListUtil::isGlob('users'));
    }

    public function test_it_expands_globs_against_table_names()
    {
        $tables = ['users', 'oauth_access_tokens', 'oauth_refresh_tokens', 'posts'];

        $this->assertSame(
            ['users', 'oauth_access_tokens', 'oauth_refresh_tokens'],
            ListUtil::expand(['users', 'oauth_*'], $tables)
        );
    }
}
