<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Forum sections, thread flags and signatures. db/schema.sql already has
 * them for a fresh database; every statement here is safe to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------------
-- Forum sections
-- ---------------------------------------------------------------------------

-- Two levels, as in a classic bulletin board: a category (parent_id NULL)
-- holds forums, and threads are posted in a forum.
CREATE TABLE IF NOT EXISTS forum_boards (
    id          SERIAL PRIMARY KEY,
    parent_id   INT REFERENCES forum_boards (id),
    name        TEXT NOT NULL CHECK (char_length(name) BETWEEN 2 AND 80),
    description TEXT NOT NULL DEFAULT '',
    position    INT  NOT NULL DEFAULT 0,
    staff_only  BOOLEAN NOT NULL DEFAULT false      -- only staff may open threads (announcements)
);

ALTER TABLE forum_threads ADD COLUMN IF NOT EXISTS board_id INT REFERENCES forum_boards (id);
ALTER TABLE forum_threads ADD COLUMN IF NOT EXISTS views INT NOT NULL DEFAULT 0;
ALTER TABLE forum_threads ADD COLUMN IF NOT EXISTS pinned BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE forum_threads ADD COLUMN IF NOT EXISTS locked BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE forum_threads ADD COLUMN IF NOT EXISTS last_post_user_id BIGINT REFERENCES users (id);
ALTER TABLE forum_threads ADD COLUMN IF NOT EXISTS edited_at TIMESTAMPTZ;
ALTER TABLE forum_posts ADD COLUMN IF NOT EXISTS edited_at TIMESTAMPTZ;
ALTER TABLE users ADD COLUMN IF NOT EXISTS forum_signature TEXT NOT NULL DEFAULT '';

CREATE INDEX IF NOT EXISTS forum_threads_board ON forum_threads (board_id, pinned DESC, last_post_at DESC) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS forum_threads_user ON forum_threads (user_id) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS forum_posts_user ON forum_posts (user_id) WHERE deleted_at IS NULL;

-- A starting layout, only when no section exists yet. The admin can change it.
DO $$
DECLARE general INT; market INT; site INT;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM forum_boards) THEN
        INSERT INTO forum_boards (name, position) VALUES ('القسم العام', 1) RETURNING id INTO general;
        INSERT INTO forum_boards (name, position) VALUES ('منتديات السوق', 2) RETURNING id INTO market;
        INSERT INTO forum_boards (name, position) VALUES ('خدمات الموقع', 3) RETURNING id INTO site;
        INSERT INTO forum_boards (parent_id, name, description, position, staff_only) VALUES
            (general, 'إعلانات الإدارة', 'إعلانات وتنبيهات إدارة الموقع', 1, true),
            (general, 'المنتدى العام', 'نقاشات السوق العامة وكل ما لا يخص شركة بعينها', 2, false),
            (general, 'الترحيب والتعارف', 'رحّب بالأعضاء الجدد وعرّف بنفسك', 3, false),
            (market, 'تحليل الأسهم', 'تحليلات الشركات ونقاش أسهمها', 1, false),
            (market, 'التحليل الفني', 'الشارتات والنماذج الفنية والدعوم والمقاومات', 2, false),
            (market, 'أخبار الشركات وإعلاناتها', 'نقاش أخبار الشركات وإعلاناتها وأثرها', 3, false),
            (market, 'المحافظ والتوصيات', 'متابعة المحافظ وتوصيات الأعضاء', 4, false),
            (market, 'الاكتتابات', 'الاكتتابات المطروحة والإدراجات الجديدة', 5, false),
            (site, 'الاقتراحات والشكاوى', 'اقتراحاتك لتطوير الموقع وبلاغات المشاكل', 1, false),
            (site, 'شرح استخدام الموقع', 'أسئلة وشروحات عن التداول والبنك وبقية الخدمات', 2, false);
    END IF;
    -- Threads written before sections existed: company threads go to stock analysis, the rest to the general forum.
    UPDATE forum_threads t SET board_id = (
        SELECT id FROM forum_boards WHERE parent_id IS NOT NULL AND name =
            CASE WHEN t.company_id IS NULL THEN 'المنتدى العام' ELSE 'تحليل الأسهم' END LIMIT 1)
    WHERE t.board_id IS NULL;
END $$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE forum_threads DROP COLUMN IF EXISTS board_id, DROP COLUMN IF EXISTS views, DROP COLUMN IF EXISTS pinned,
                DROP COLUMN IF EXISTS locked, DROP COLUMN IF EXISTS last_post_user_id, DROP COLUMN IF EXISTS edited_at;
            ALTER TABLE forum_posts DROP COLUMN IF EXISTS edited_at;
            ALTER TABLE users DROP COLUMN IF EXISTS forum_signature;
            DROP TABLE IF EXISTS forum_boards;
            SQL);
    }
};
