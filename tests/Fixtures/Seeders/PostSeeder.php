<?php

namespace Ramadan\CustomFresh\Tests\Fixtures\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PostSeeder extends Seeder
{
    /**
     * Seed the dropped posts table from the preserved user row.
     *
     * @return void
     */
    public function run()
    {
        $userId = DB::table('cf_users')->value('id');

        if ($userId === null) {
            return;
        }

        DB::table('cf_posts')->insert([
            'user_id'    => $userId,
            'title'      => 'seeded-post',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
