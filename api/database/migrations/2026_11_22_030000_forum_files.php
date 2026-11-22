<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Forum uploads. db/schema.sql has the table for a fresh database; this is safe to run again. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- Images and attachments members upload to use in forum posts. The file
-- itself is on disk under a random name; `name` is what the member called it.
CREATE TABLE IF NOT EXISTS forum_files (
    id         BIGSERIAL PRIMARY KEY,
    user_id    BIGINT NOT NULL REFERENCES users (id),
    name       TEXT   NOT NULL,
    path       TEXT   NOT NULL,
    mime       TEXT   NOT NULL,
    size       BIGINT NOT NULL CHECK (size > 0),
    is_image   BOOLEAN NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS forum_files_user ON forum_files (user_id, created_at);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS forum_files');
    }
};
