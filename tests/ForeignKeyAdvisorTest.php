<?php

namespace Ramadan\CustomFresh\Tests;

use Ramadan\CustomFresh\Support\ForeignKeyAdvisor;

class ForeignKeyAdvisorTest extends TestCase
{
    public function test_sort_drop_order_drops_children_before_parents()
    {
        $advisor = new class('testing') extends ForeignKeyAdvisor {
            public function edges(array $tables)
            {
                return [
                    ['from' => 'cf_posts', 'to' => 'cf_users'],
                    ['from' => 'cf_comments', 'to' => 'cf_posts'],
                ];
            }
        };

        $this->assertSame(
            ['cf_comments', 'cf_posts', 'cf_users'],
            $advisor->sortDropOrder(['cf_users', 'cf_posts', 'cf_comments'])
        );
    }

    public function test_blocking_constraints_are_foreign_keys_on_kept_children()
    {
        $advisor = new class('testing') extends ForeignKeyAdvisor {
            public function constraints(array $tables)
            {
                return [
                    [
                        'from'    => 'cf_posts',
                        'to'      => 'cf_users',
                        'name'    => 'cf_posts_user_id_foreign',
                        'columns' => ['user_id'],
                    ],
                    [
                        'from'    => 'cf_oauth_tokens',
                        'to'      => 'cf_users',
                        'name'    => null,
                        'columns' => ['user_id'],
                    ],
                ];
            }
        };

        $this->assertSame(
            [[
                'from'    => 'cf_posts',
                'to'      => 'cf_users',
                'name'    => 'cf_posts_user_id_foreign',
                'columns' => ['user_id'],
            ]],
            $advisor->blockingConstraints(['cf_posts'], ['cf_users', 'cf_oauth_tokens'])
        );
    }

    public function test_expand_referenced_keeps_parent_tables()
    {
        $advisor = new class('testing') extends ForeignKeyAdvisor {
            public function edges(array $tables)
            {
                return [
                    ['from' => 'cf_posts', 'to' => 'cf_users'],
                    ['from' => 'cf_comments', 'to' => 'cf_posts'],
                ];
            }
        };

        $result = $advisor->expandReferenced(
            ['cf_posts'],
            ['cf_users', 'cf_posts', 'cf_comments']
        );

        $this->assertContains('cf_posts', $result['preserved']);
        $this->assertContains('cf_users', $result['preserved']);
        $this->assertNotContains('cf_comments', $result['preserved']);
        $this->assertSame([], $result['notes']);
        $this->assertContains(
            'Preserved table [cf_posts] references [cf_users], which cannot be dropped.',
            $result['warnings']
        );
        $this->assertNotContains(
            'Pass --drop-referenced to drop those referenced tables instead.',
            $result['warnings']
        );
    }

    public function test_expand_referenced_honors_the_exclude_list()
    {
        $advisor = new class('testing') extends ForeignKeyAdvisor {
            public function edges(array $tables)
            {
                return [
                    ['from' => 'cf_posts', 'to' => 'cf_users'],
                ];
            }
        };

        $result = $advisor->expandReferenced(
            ['cf_posts'],
            ['cf_users', 'cf_posts'],
            ['cf_users']
        );

        $this->assertSame(['cf_posts'], $result['preserved']);
        $this->assertSame([], $result['notes']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_expand_dependents_keeps_child_tables()
    {
        $advisor = new class('testing') extends ForeignKeyAdvisor {
            public function edges(array $tables)
            {
                return [
                    ['from' => 'cf_posts', 'to' => 'cf_users'],
                ];
            }
        };

        $result = $advisor->expandDependents(
            ['cf_users'],
            ['cf_users', 'cf_posts']
        );

        $this->assertContains('cf_users', $result['preserved']);
        $this->assertContains('cf_posts', $result['preserved']);
        $this->assertContains(
            'Also preserving [cf_posts] because it references [cf_users].',
            $result['notes']
        );
        $this->assertSame([], $result['warnings']);
    }

    public function test_expand_related_notes_parent_tables_instead_of_warning()
    {
        $advisor = new class('testing') extends ForeignKeyAdvisor {
            public function edges(array $tables)
            {
                return [
                    ['from' => 'cf_posts', 'to' => 'cf_users'],
                ];
            }
        };

        $result = $advisor->expandRelated(
            ['cf_posts'],
            ['cf_users', 'cf_posts']
        );

        $this->assertContains('cf_users', $result['preserved']);
        $this->assertContains(
            'Also preserving [cf_users] because [cf_posts] references it.',
            $result['notes']
        );
        $this->assertSame([], $result['warnings']);
    }
}
