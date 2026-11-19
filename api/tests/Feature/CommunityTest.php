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
}
