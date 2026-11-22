<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class CommunityTest extends TestCase
{
    use RefreshDatabase;

    private array $user;

    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection('engine')->del('market:events');
        DB::table('companies')->insert(['symbol' => '2222', 'name' => 'أرامكو', 'shares_outstanding' => 1, 'tick_size' => 1, 'ref_price' => 2700]);
        $register = fn ($alias, $mobile) => $this->postJson('/api/v1/register', [
            'name' => $alias, 'alias' => $alias, 'mobile' => $mobile, 'password' => 'secret-pass-1'])->json();
        $this->user = $register('talker', '0500000001');
        $this->admin = $register('boss', '0500000002');
        DB::table('users')->where('id', $this->admin['user']['id'])->update(['role' => 'admin']);
    }

    private function as(array $who)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($who['token']);
    }

    private function events(): array
    {
        return array_map(fn ($f) => json_decode($f['data'], true),
            array_values(Redis::connection('engine')->xrange('market:events', '-', '+')));
    }

    public function test_chat_message_is_stored_and_published_to_the_room(): void
    {
        $this->postJson('/api/v1/chat/general', ['text' => 'hi'])->assertStatus(401);
        $id = $this->as($this->user)->postJson('/api/v1/chat/2222', ['text' => '  السهم قوي اليوم  '])->assertCreated()->json('id');

        $this->assertSame([['type' => 'chat', 'room' => '2222', 'id' => $id, 'alias' => 'talker', 'text' => 'السهم قوي اليوم']], $this->events());
        $this->getJson('/api/v1/chat/2222')->assertOk()->assertJsonPath('messages.0.text', 'السهم قوي اليوم')->assertJsonPath('messages.0.alias', 'talker');
        $this->getJson('/api/v1/chat/general')->assertOk()->assertJsonCount(0, 'messages');

        $this->as($this->user)->postJson('/api/v1/chat/9999', ['text' => 'x'])->assertStatus(404);
        $this->as($this->user)->postJson('/api/v1/chat/general', ['text' => str_repeat('ا', 301)])->assertStatus(422);
        $this->as($this->user)->postJson('/api/v1/chat/general', ['text' => '   '])->assertStatus(422);
        $this->assertSame(1, DB::table('chat_messages')->count());
    }

    public function test_moderation_delete_and_mute(): void
    {
        $id = $this->as($this->user)->postJson('/api/v1/chat/general', ['text' => 'رسالة مخالفة'])->json('id');

        $this->as($this->user)->deleteJson("/api/v1/chat/messages/$id")->assertStatus(403);
        $this->as($this->user)->putJson('/api/v1/chat/mute/boss', ['minutes' => 10])->assertStatus(403);

        $this->as($this->admin)->deleteJson("/api/v1/chat/messages/$id")->assertOk();
        $this->getJson('/api/v1/chat/general')->assertJsonCount(0, 'messages');
        $this->assertSame(['type' => 'chat_delete', 'room' => 'general', 'id' => $id], $this->events()[1]);

        $this->as($this->admin)->putJson('/api/v1/chat/mute/talker', ['minutes' => 30])->assertOk();
        $this->as($this->user)->postJson('/api/v1/chat/general', ['text' => 'هل أنا مكتوم؟'])->assertStatus(403);
        $this->as($this->admin)->putJson('/api/v1/chat/mute/talker', ['minutes' => 0])->assertOk();
        $this->as($this->user)->postJson('/api/v1/chat/general', ['text' => 'رجعت'])->assertCreated();
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'chat.mute')->count());
    }

    public function test_forum_threads_replies_and_removal(): void
    {
        $this->postJson('/api/v1/forum', ['title' => 'تحليل', 'body' => 'نص'])->assertStatus(401);
        $general = $this->as($this->user)->postJson('/api/v1/forum', ['title' => 'نظرة على السوق', 'body' => 'السوق في اتجاه صاعد.'])->assertCreated()->json('id');
        $aramco = $this->as($this->user)->postJson('/api/v1/forum', ['title' => 'تحليل أرامكو', 'body' => 'دعم عند 26.50.', 'symbol' => '2222'])->assertCreated()->json('id');
        $this->as($this->user)->postJson('/api/v1/forum', ['title' => 'x', 'body' => 'قصير'])->assertStatus(422);
        $this->as($this->user)->postJson('/api/v1/forum', ['title' => 'شركة غير موجودة', 'body' => 'نص', 'symbol' => '9999'])->assertStatus(422);

        $this->as($this->admin)->postJson("/api/v1/forum/$general/posts", ['body' => 'أتفق معك.'])->assertCreated();
        $this->as($this->user)->postJson('/api/v1/forum/999999/posts', ['body' => 'رد'])->assertStatus(404);

        // The thread with the newest reply comes first.
        $this->getJson('/api/v1/forum')->assertOk()->assertJsonCount(2, 'threads')
            ->assertJsonPath('threads.0.id', $general)->assertJsonPath('threads.0.reply_count', 1);
        $this->getJson('/api/v1/forum?symbol=2222')->assertJsonCount(1, 'threads')->assertJsonPath('threads.0.id', $aramco);
        $this->getJson("/api/v1/forum/$general")->assertOk()
            ->assertJsonPath('thread.alias', 'talker')->assertJsonPath('posts.0.body', 'أتفق معك.')->assertJsonPath('posts.0.alias', 'boss');

        $this->as($this->user)->deleteJson("/api/v1/forum/$general")->assertStatus(403);
        $this->as($this->admin)->deleteJson("/api/v1/forum/$general")->assertOk();
        $this->getJson("/api/v1/forum/$general")->assertStatus(404);
        $this->getJson('/api/v1/forum')->assertJsonCount(1, 'threads');
        $this->as($this->user)->postJson("/api/v1/forum/$general/posts", ['body' => 'رد على محذوف'])->assertStatus(404);
    }

    public function test_forum_sections_pages_and_moderation(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class); // more posts than the per-minute limit
        // The starting layout: three categories, forums inside them.
        $index = $this->getJson('/api/v1/forum/boards')->assertOk()
            ->assertJsonCount(3, 'categories')->assertJsonPath('categories.0.name', 'القسم العام')
            ->assertJsonPath('categories.0.boards.0.name', 'إعلانات الإدارة')->assertJsonPath('categories.0.boards.0.staff_only', true)
            ->assertJsonPath('stats.threads', 0)->assertJsonPath('stats.members', 2)->assertJsonPath('stats.newest_member', 'boss')
            ->json();
        $announcements = $index['categories'][0]['boards'][0]['id'];
        $general = $index['categories'][0]['boards'][1]['id'];
        $analysis = $index['categories'][1]['boards'][0]['id'];

        // Only staff open threads in the announcements forum; anyone may reply there.
        $this->as($this->user)->postJson('/api/v1/forum', ['title' => 'إعلان مني', 'body' => 'نص', 'board_id' => $announcements])->assertStatus(403);
        $notice = $this->as($this->admin)->postJson('/api/v1/forum', ['title' => 'قوانين المنتدى', 'body' => 'اقرأها.', 'board_id' => $announcements])->assertCreated()->json('id');
        $this->as($this->user)->postJson("/api/v1/forum/$notice/posts", ['body' => 'تم.'])->assertCreated();
        $this->as($this->user)->postJson('/api/v1/forum', ['title' => 'بلا منتدى', 'body' => 'نص', 'board_id' => 999999])->assertStatus(404);
        // With no forum chosen, a company thread lands in stock analysis.
        $auto = $this->as($this->user)->postJson('/api/v1/forum', ['title' => 'تحليل أرامكو', 'body' => 'نص', 'symbol' => '2222'])->assertCreated()->json('id');
        $this->assertSame($analysis, DB::table('forum_threads')->where('id', $auto)->value('board_id'));

        // A long thread: 17 replies make two pages of 15 posts, the opening post counted.
        $thread = $this->as($this->user)->postJson('/api/v1/forum', ['title' => 'نقاش طويل', 'body' => 'البداية', 'board_id' => $general])->assertCreated()->json('id');
        foreach (range(1, 17) as $n) {
            DB::table('forum_posts')->insert(['thread_id' => $thread, 'user_id' => $this->adminId(), 'body' => "رد $n"]);
        }
        DB::table('forum_threads')->where('id', $thread)->update(['reply_count' => 17]);
        $this->getJson("/api/v1/forum/$thread")->assertOk()
            ->assertJsonPath('pages', 2)->assertJsonPath('page', 1)->assertJsonCount(14, 'posts')
            ->assertJsonPath('thread.author.alias', 'talker')->assertJsonPath('thread.author.rank', 'عضو جديد')
            ->assertJsonPath('thread.author.posts', 3)->assertJsonPath('thread.board', 'المنتدى العام')
            ->assertJsonPath('posts.0.number', 2)->assertJsonPath('posts.0.author.rank', 'مدير الموقع')
            ->assertJsonPath('thread.views', 1);
        $this->getJson("/api/v1/forum/$thread?page=last")->assertOk()
            ->assertJsonPath('page', 2)->assertJsonCount(3, 'posts')
            ->assertJsonPath('posts.0.number', 16)->assertJsonPath('posts.2.body', 'رد 17')->assertJsonPath('thread.views', 2);

        // The forum's thread list, and the front page counts and last post.
        $this->getJson("/api/v1/forum?board=$general")->assertOk()
            ->assertJsonPath('board.name', 'المنتدى العام')->assertJsonPath('board.category', 'القسم العام')
            ->assertJsonPath('total', 1)->assertJsonPath('pages', 1)
            ->assertJsonPath('threads.0.reply_count', 17)->assertJsonPath('threads.0.last_alias', 'talker');
        $this->getJson('/api/v1/forum?board=999999')->assertStatus(404);
        $this->getJson('/api/v1/forum/boards')->assertOk()
            ->assertJsonPath('categories.0.boards.1.threads', 1)->assertJsonPath('categories.0.boards.1.posts', 18)
            ->assertJsonPath('categories.0.boards.0.last.title', 'قوانين المنتدى')->assertJsonPath('categories.0.boards.0.last.alias', 'talker')
            ->assertJsonPath('stats.threads', 3)->assertJsonPath('stats.posts', 21);

        // Editing: the author edits their own thread and reply; another member cannot.
        $this->as($this->user)->putJson("/api/v1/forum/$thread", ['title' => 'نقاش طويل جدًا', 'body' => 'بداية معدلة'])->assertOk();
        $this->as($this->user)->putJson("/api/v1/forum/$notice", ['title' => 'عنوان مخترق'])->assertStatus(403);
        $reply = DB::table('forum_posts')->where('thread_id', $notice)->value('id');
        $this->as($this->user)->putJson("/api/v1/forum/posts/$reply", ['body' => 'تم، شكرًا.'])->assertOk();
        $adminReply = DB::table('forum_posts')->where('thread_id', $thread)->value('id');
        $this->as($this->user)->putJson("/api/v1/forum/posts/$adminReply", ['body' => 'تعديل رد غيري'])->assertStatus(403);
        $this->assertNotNull(DB::table('forum_posts')->where('id', $reply)->value('edited_at'));

        // Moderation: pin, lock, move. Members cannot; a locked thread takes no member replies.
        $this->as($this->user)->putJson("/api/v1/forum/$thread", ['pinned' => true])->assertStatus(403);
        $other = $this->as($this->user)->postJson('/api/v1/forum', ['title' => 'موضوع أحدث', 'body' => 'نص', 'board_id' => $general])->assertCreated()->json('id');
        $this->as($this->admin)->putJson("/api/v1/forum/$thread", ['pinned' => true, 'locked' => true])->assertOk();
        $this->getJson("/api/v1/forum?board=$general")->assertJsonPath('threads.0.id', $thread)->assertJsonPath('threads.0.pinned', true)
            ->assertJsonPath('threads.1.id', $other);
        $this->as($this->user)->postJson("/api/v1/forum/$thread/posts", ['body' => 'رد على مغلق'])->assertStatus(403);
        $this->as($this->admin)->postJson("/api/v1/forum/$thread/posts", ['body' => 'رد الإدارة'])->assertCreated();
        $this->as($this->admin)->putJson("/api/v1/forum/$other", ['board_id' => $analysis])->assertOk();
        $this->getJson("/api/v1/forum?board=$analysis")->assertJsonPath('total', 2);

        // Staff remove a single reply; the count follows.
        $this->as($this->user)->deleteJson("/api/v1/forum/posts/$adminReply")->assertStatus(403);
        $this->as($this->admin)->deleteJson("/api/v1/forum/posts/$adminReply")->assertOk();
        $this->assertSame(17, (int) DB::table('forum_threads')->where('id', $thread)->value('reply_count'));

        // Signature, shown with the member's posts.
        $this->as($this->user)->putJson('/api/v1/forum/signature', ['signature' => 'الصبر مفتاح الربح'])->assertOk();
        $this->getJson("/api/v1/forum/$other")->assertJsonPath('thread.author.signature', 'الصبر مفتاح الربح');
        $this->as($this->user)->getJson('/api/v1/me')->assertJsonPath('user.forum_signature', 'الصبر مفتاح الربح');
    }

    public function test_admin_manages_forum_sections(): void
    {
        $this->as($this->user)->postJson('/api/v1/admin/forum/boards', ['name' => 'قسم'])->assertStatus(403);
        $category = $this->as($this->admin)->postJson('/api/v1/admin/forum/boards', ['name' => 'قسم جديد'])->assertCreated()->json('id');
        $board = $this->as($this->admin)->postJson('/api/v1/admin/forum/boards', ['name' => 'منتدى جديد', 'description' => 'وصف', 'parent_id' => $category])->assertCreated()->json('id');
        // A forum cannot sit inside another forum.
        $this->as($this->admin)->postJson('/api/v1/admin/forum/boards', ['name' => 'فرعي', 'parent_id' => $board])->assertStatus(422);
        $this->getJson('/api/v1/forum/boards')->assertJsonCount(4, 'categories')
            ->assertJsonPath('categories.3.name', 'قسم جديد')->assertJsonPath('categories.3.boards.0.description', 'وصف');

        $this->as($this->admin)->putJson("/api/v1/admin/forum/boards/$board", ['name' => 'منتدى معدل', 'staff_only' => true])->assertOk();
        $this->getJson('/api/v1/forum/boards')->assertJsonPath('categories.3.boards.0.name', 'منتدى معدل')->assertJsonPath('categories.3.boards.0.staff_only', true);

        // Not while it holds something.
        $thread = $this->as($this->admin)->postJson('/api/v1/forum', ['title' => 'موضوع', 'body' => 'نص', 'board_id' => $board])->assertCreated()->json('id');
        $this->as($this->admin)->deleteJson("/api/v1/admin/forum/boards/$category")->assertStatus(422);
        $this->as($this->admin)->deleteJson("/api/v1/admin/forum/boards/$board")->assertStatus(422);
        $this->as($this->admin)->deleteJson("/api/v1/forum/$thread")->assertOk();
        $this->as($this->admin)->deleteJson("/api/v1/admin/forum/boards/$board")->assertOk();
        $this->as($this->admin)->deleteJson("/api/v1/admin/forum/boards/$category")->assertOk();
        $this->getJson('/api/v1/forum/boards')->assertJsonCount(3, 'categories');
    }

    public function test_forum_uploads_images_and_attachments(): void
    {
        \Illuminate\Support\Facades\Storage::fake('forum');
        $file = fn (string $name, int $kb = 10) => \Illuminate\Http\UploadedFile::fake()->create($name, $kb);
        $image = \Illuminate\Http\UploadedFile::fake()->image('شارت أرامكو.png', 400, 300);

        $this->post('/api/v1/forum/uploads', ['file' => $image], ['Accept' => 'application/json'])->assertStatus(401);
        $up = fn ($f) => $this->as($this->user)->post('/api/v1/forum/uploads', ['file' => $f], ['Accept' => 'application/json']);

        // A real picture is stored and served inline, to anyone, cacheable and never sniffed.
        $id = $up($image)->assertCreated()->assertJsonPath('is_image', true)->assertJsonPath('name', 'شارت أرامكو.png')->json('id');
        $this->get("/api/v1/forum/files/$id")->assertOk()
            ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('immutable', $this->get("/api/v1/forum/files/$id")->headers->get('Cache-Control'));

        // A document comes back as a download, never as a page.
        $doc = $up(\Illuminate\Http\UploadedFile::fake()->createWithContent('تقرير.txt', "نتائج الربع الثالث\n"))->assertCreated()
            ->assertJsonPath('is_image', false)->json('id');
        $res = $this->get("/api/v1/forum/files/$doc")->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringStartsWith('attachment;', $res->headers->get('Content-Disposition'));

        // Refused: a script, a web page, an SVG, something named .png that is not a picture, and oversized files.
        $up($file('shell.php'))->assertStatus(422);
        $up(\Illuminate\Http\UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'))->assertStatus(422);
        $up(\Illuminate\Http\UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>'))->assertStatus(422);
        $up(\Illuminate\Http\UploadedFile::fake()->createWithContent('fake.png', 'not a picture at all'))->assertStatus(422);
        $up($file('big.pdf', 10241))->assertStatus(422);
        $this->get('/api/v1/forum/files/999999')->assertStatus(404);
        $this->assertSame(2, DB::table('forum_files')->count());
    }

    private function adminId(): int
    {
        return (int) DB::table('users')->where('alias', 'boss')->value('id');
    }
}
