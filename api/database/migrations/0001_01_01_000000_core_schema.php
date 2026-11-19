<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The schema lives in one place, db/schema.sql at the repository root, because
 * the Go engine and its tests use the same file. This migration loads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(base_path('../db/schema.sql')));
    }

    public function down(): void
    {
        DB::unprepared('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
    }
};
