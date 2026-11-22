<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Company file fields. db/schema.sql already has them for a fresh database;
 * this adds them to an existing one and is safe to run when they exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE companies ADD COLUMN IF NOT EXISTS about TEXT NOT NULL DEFAULT '';
            ALTER TABLE companies ADD COLUMN IF NOT EXISTS founded_year INT CHECK (founded_year BETWEEN 1800 AND 2200);
            ALTER TABLE companies ADD COLUMN IF NOT EXISTS headquarters TEXT NOT NULL DEFAULT '';
            ALTER TABLE companies ADD COLUMN IF NOT EXISTS website TEXT NOT NULL DEFAULT '';
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE companies DROP COLUMN IF EXISTS about, DROP COLUMN IF EXISTS founded_year,
            DROP COLUMN IF EXISTS headquarters, DROP COLUMN IF EXISTS website');
    }
};
