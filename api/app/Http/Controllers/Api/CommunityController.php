<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Chat and forum. Chat messages are stored, then published on the engine's
 * event stream so the live feed delivers them to everyone in the room.
 */
class CommunityController extends Controller
{
    // ------------------------------------------------------------- chat

    public function chatHistory(string $room): JsonResponse
    {
        $this->checkRoom($room);
        $rows = DB::table('chat_messages as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.room', $room)->whereNull('m.deleted_at')
            ->orderByDesc('m.id')->limit(60)
            ->get(['m.id', 'u.alias', 'm.body as text', 'm.created_at'])->reverse()->values();

        return response()->json(['room' => $room, 'messages' => $rows]);
    }

    public function chatSend(Request $request, string $room): JsonResponse
    {
        $this->checkRoom($room);
        $text = trim($request->validate(['text' => ['required', 'string', 'max:300']])['text']);
        abort_if($text === '', 422, 'الرسالة فارغة');
        $user = $request->user();
        $muted = DB::table('users')->where('id', $user->id)->whereRaw('chat_muted_until > now()')->exists();
        if ($muted) {
            return response()->json(['message' => 'تم كتمك في الشات مؤقتًا'], 403);
        }

        $id = DB::table('chat_messages')->insertGetId(['room' => $room, 'user_id' => $user->id, 'body' => $text]);
        $this->publish(['type' => 'chat', 'room' => $room, 'id' => $id, 'alias' => $user->alias, 'text' => $text]);

        return response()->json(['id' => $id], 201);
    }

    /** Admin or moderator: remove a message for everyone. */
    public function chatDelete(Request $request, int $id): JsonResponse
    {
        $this->staffOnly($request);
        $message = DB::table('chat_messages')->where('id', $id)->whereNull('deleted_at')->first();
        abort_if($message === null, 404, 'الرسالة غير موجودة');
        DB::table('chat_messages')->where('id', $id)->update(['deleted_at' => DB::raw('now()')]);
        $this->publish(['type' => 'chat_delete', 'room' => $message->room, 'id' => $id]);
        $this->audit($request, 'chat.delete', ['id' => $id, 'user_id' => $message->user_id]);

        return response()->json(['status' => 'deleted']);
    }

    /** Admin or moderator: stop a user from chatting for some minutes (0 lifts it). */
    public function mute(Request $request, string $alias): JsonResponse
    {
        $this->staffOnly($request);
        $minutes = (int) $request->validate(['minutes' => ['required', 'integer', 'between:0,43200']])['minutes'];
        $updated = DB::table('users')->where('alias', $alias)->where('role', 'trader')->update([
            'chat_muted_until' => $minutes === 0 ? null : DB::raw("now() + interval '".$minutes." minutes'"),
        ]);
        abort_if($updated === 0, 404, 'المستخدم غير موجود');
        $this->audit($request, 'chat.mute', ['alias' => $alias, 'minutes' => $minutes]);

        return response()->json(['status' => $minutes === 0 ? 'unmuted' : 'muted']);
    }

    // ------------------------------------------------------------ forum

    public function threads(Request $request): JsonResponse
    {
        $query = DB::table('forum_threads as t')->join('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('companies as c', 'c.id', '=', 't.company_id')
            ->whereNull('t.deleted_at')->orderByDesc('t.last_post_at')->orderByDesc('t.id')->limit(50)
            ->select('t.id', 't.title', 'u.alias', 'c.symbol', 'c.name as company', 't.reply_count', 't.last_post_at', 't.created_at');
        if ($symbol = $request->query('symbol')) {
            $query->where('c.symbol', $symbol);
        }

        return response()->json(['threads' => $query->get()]);
    }

    public function thread(int $id): JsonResponse
    {
        $thread = DB::table('forum_threads as t')->join('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('companies as c', 'c.id', '=', 't.company_id')
            ->where('t.id', $id)->whereNull('t.deleted_at')
            ->first(['t.id', 't.title', 't.body', 'u.alias', 'c.symbol', 'c.name as company', 't.created_at']);
        abort_if($thread === null, 404, 'الموضوع غير موجود');
        $posts = DB::table('forum_posts as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.thread_id', $id)->whereNull('p.deleted_at')->orderBy('p.id')->limit(500)
            ->get(['p.id', 'u.alias', 'p.body', 'p.created_at']);

        return response()->json(['thread' => $thread, 'posts' => $posts]);
    }

    public function createThread(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'body' => ['required', 'string', 'max:10000'],
            'symbol' => ['nullable', 'string', 'exists:companies,symbol'],
        ]);
        $id = DB::table('forum_threads')->insertGetId([
            'user_id' => $request->user()->id, 'title' => trim($data['title']), 'body' => trim($data['body']),
            'company_id' => empty($data['symbol']) ? null : DB::table('companies')->where('symbol', $data['symbol'])->value('id'),
        ]);

        return response()->json(['id' => $id], 201);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $body = trim($request->validate(['body' => ['required', 'string', 'max:10000']])['body']);
        abort_if($body === '', 422, 'الرد فارغ');

        return DB::transaction(function () use ($id, $body, $request) {
            $updated = DB::table('forum_threads')->where('id', $id)->whereNull('deleted_at')
                ->update(['reply_count' => DB::raw('reply_count + 1'), 'last_post_at' => DB::raw('clock_timestamp()')]);
            abort_if($updated === 0, 404, 'الموضوع غير موجود');
            $postId = DB::table('forum_posts')->insertGetId(['thread_id' => $id, 'user_id' => $request->user()->id, 'body' => $body]);

            return response()->json(['id' => $postId], 201);
        });
    }

    /** Admin or moderator: hide a thread and everything in it. */
    public function deleteThread(Request $request, int $id): JsonResponse
    {
        $this->staffOnly($request);
        $updated = DB::table('forum_threads')->where('id', $id)->whereNull('deleted_at')->update(['deleted_at' => DB::raw('now()')]);
        abort_if($updated === 0, 404, 'الموضوع غير موجود');
        $this->audit($request, 'forum.delete_thread', ['id' => $id]);

        return response()->json(['status' => 'deleted']);
    }

    // ---------------------------------------------------------- helpers

    private function checkRoom(string $room): void
    {
        abort_unless($room === 'general' || DB::table('companies')->where('symbol', $room)->exists(), 404, 'الغرفة غير موجودة');
    }

    private function staffOnly(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['admin', 'moderator'], true), 403, 'للمشرفين فقط');
    }

    private function publish(array $event): void
    {
        Redis::connection('engine')->xadd('market:events', '*', ['data' => json_encode($event, JSON_UNESCAPED_UNICODE)]);
    }

    private function audit(Request $request, string $action, array $details): void
    {
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE), 'ip' => $request->ip()]);
    }
}
