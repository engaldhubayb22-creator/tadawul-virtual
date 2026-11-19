<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MakeAdmin extends Command
{
    protected $signature = 'app:make-admin {mobile : The mobile number of a registered user}';

    protected $description = 'Give a registered user the admin role';

    public function handle(): int
    {
        $updated = DB::table('users')->where('mobile', $this->argument('mobile'))->update(['role' => 'admin']);
        if ($updated === 0) {
            $this->error('No user with that mobile number.');

            return self::FAILURE;
        }
        $this->info('User is now an admin.');

        return self::SUCCESS;
    }
}
