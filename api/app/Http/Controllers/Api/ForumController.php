<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The forum, laid out like a classic bulletin board: categories hold forums,
 * forums hold threads, threads hold posts. A thread's opening post is the
 * thread row itself; replies are forum_posts.
 */
class ForumController extends Controller
{
    private const THREADS_PER_PAGE = 25;

    private const POSTS_PER_PAGE = 15;

    // ------------------------------------------------------------ reading

    /** The front page: categories, their forums with counts and last post, and the board statistics. */
    public function index(): JsonResponse
    {
        $boards = DB::select(<<<'SQL'
            SELECT b.id, b.parent_id, b.name, b.description, b.position, b.staff_only,
                   COALESCE(n.threads, 0) AS threads, COALESCE(n.posts, 0) AS posts,
                   l.id AS last_id, l.title AS last_title, l.last_post_at, lu.alias AS last_alias
            FROM forum_boards b
            LEFT JOIN LATERAL (
                SELECT count(*) AS threads, count(*) + COALESCE(sum(reply_count), 0) AS posts
                FROM forum_threads WHERE board_id = b.id AND deleted_at IS NULL) n ON true
            LEFT JOIN LATERAL (
                SELECT id, title, last_post_at, COALESCE(last_post_user_id, user_id) AS by
                FROM forum_threads WHERE board_id = b.id AND deleted_at IS NULL
                ORDER BY last_post_at DESC, id DESC LIMIT 1) l ON true
            LEFT JOIN users lu ON lu.id = l.by
            ORDER BY b.position, b.id
            SQL);

        $categories = [];
        foreach ($boards as $b) {
            if ($b->parent_id === null) {
                $categories[$b->id] = ['id' => (int) $b->id, 'name' => $b->name, 'position' => (int) $b->position, 'boards' => []];
            }
        }
        foreach ($boards as $b) {
            if ($b->parent_id !== null && isset($categories[$b->parent_id])) {
                $categories[$b->parent_id]['boards'][] = [
                    'id' => (int) $b->id, 'name' => $b->name, 'description' => $b->description,
                    'position' => (int) $b->position, 'staff_only' => (bool) $b->staff_only,
                    'threads' => (int) $b->threads, 'posts' => (int) $b->posts,
                    'last' => $b->last_id === null ? null : [
                        'thread_id' => (int) $b->last_id, 'title' => $b->last_title, 'alias' => $b->last_alias, 'at' => $b->last_post_at,
                    ],
                ];
            }
        }

        $threads = DB::table('forum_threads')->whereNull('deleted_at');
        $members = DB::table('users')->where('role', '<>', 'bot');

        return response()->json([
            'categories' => array_values($categories),
            'stats' => [
                'threads' => (clone $threads)->count(),
                'posts' => (clone $threads)->count() + (int) (clone $threads)->sum('reply_count'),
                'members' => (clone $members)->count(),
                'newest_member' => (clone $members)->orderByDesc('id')->value('alias'),
            ],
        ]);
    }

    /**
     * Threads, pinned first then by latest post. ?board= lists one forum by
     * page; without it, the newest 50 across the forum (optionally ?symbol=).
     */
    public function threads(Request $request): JsonResponse
    {
        $query = DB::table('forum_threads as t')->join('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('users as lu', 'lu.id', '=', DB::raw('COALESCE(t.last_post_user_id, t.user_id)'))
            ->leftJoin('companies as c', 'c.id', '=', 't.company_id')
            ->whereNull('t.deleted_at')
            ->select('t.id', 't.board_id', 't.title', 'u.alias', 'c.symbol', 'c.name as company', 't.reply_count', 't.views',
                't.pinned', 't.locked', 't.last_post_at', 'lu.alias as last_alias', 't.created_at');
        if ($symbol = $request->query('symbol')) {
            $query->where('c.symbol', $symbol);
        }

        $boardId = (int) $request->query('board', 0);
        if ($boardId === 0) {
            return response()->json(['threads' => $query->orderByDesc('t.last_post_at')->orderByDesc('t.id')->limit(50)->get()]);
        }

        $board = $this->board($boardId);
        $query->where('t.board_id', $boardId);
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::THREADS_PER_PAGE));
        $page = min(max((int) $request->query('page', 1), 1), $pages);

        return response()->json([
            'board' => [
                'id' => (int) $board->id, 'name' => $board->name, 'description' => $board->description,
                'staff_only' => (bool) $board->staff_only,
                'category' => DB::table('forum_boards')->where('id', $board->parent_id)->value('name'),
            ],
            'threads' => $query->orderByDesc('t.pinned')->orderByDesc('t.last_post_at')->orderByDesc('t.id')
                ->forPage($page, self::THREADS_PER_PAGE)->get(),
            'page' => $page, 'pages' => $pages, 'total' => $total,
        ]);
    }

    /**
     * One thread with a page of posts. Post number 1 is the opening post; it
     * comes back as `thread` on every page and the replies of the page as
     * `posts`. ?page=last jumps to the newest replies.
     */
    public function thread(Request $request, int $id): JsonResponse
    {
        $thread = DB::table('forum_threads as t')->join('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('companies as c', 'c.id', '=', 't.company_id')
            ->leftJoin('forum_boards as b', 'b.id', '=', 't.board_id')
            ->where('t.id', $id)->whereNull('t.deleted_at')
            ->first(['t.id', 't.title', 't.body', 't.user_id', 'u.alias', 'c.symbol', 'c.name as company', 't.created_at',
                't.edited_at', 't.reply_count', 't.views', 't.pinned', 't.locked', 't.board_id', 'b.name as board']);
        abort_if($thread === null, 404, 'الموضوع غير موجود');
        DB::table('forum_threads')->where('id', $id)->increment('views');

        // The first page shows the opening post and one reply fewer.
        $replies = (int) $thread->reply_count;
        $per = self::POSTS_PER_PAGE;
        $pages = max(1, (int) ceil(($replies + 1) / $per));
        $page = $request->query('page') === 'last' ? $pages : min(max((int) $request->query('page', 1), 1), $pages);
        $offset = $page === 1 ? 0 : ($page - 1) * $per - 1;
        $posts = DB::table('forum_posts as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.thread_id', $id)->whereNull('p.deleted_at')->orderBy('p.id')
            ->offset($offset)->limit($page === 1 ? $per - 1 : $per)
            ->get(['p.id', 'p.user_id', 'u.alias', 'p.body', 'p.created_at', 'p.edited_at']);

        $authors = $this->authors($posts->pluck('user_id')->push($thread->user_id)->unique()->all());
        $number = $offset + 2; // the opening post is number 1
        foreach ($posts as $post) {
            $post->number = $number++;
            $post->author = $authors[$post->user_id] ?? null;
        }
        $thread->author = $authors[$thread->user_id] ?? null;
        $thread->views = (int) $thread->views + 1;

        return response()->json(['thread' => $thread, 'posts' => $posts, 'page' => $page, 'pages' => $pages]);
    }

    // ------------------------------------------------------------ writing

    public function createThread(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'body' => ['required', 'string', 'max:10000'],
            'symbol' => ['nullable', 'string', 'exists:companies,symbol'],
            'board_id' => ['nullable', 'integer'],
        ]);
        $companyId = empty($data['symbol']) ? null : DB::table('companies')->where('symbol', $data['symbol'])->value('id');
        // No forum chosen: company threads go to stock analysis, the rest to the general forum.
        $board = empty($data['board_id'])
            ? DB::table('forum_boards')->whereNotNull('parent_id')
                ->where('name', $companyId === null ? 'المنتدى العام' : 'تحليل الأسهم')->first()
                ?? DB::table('forum_boards')->whereNotNull('parent_id')->where('staff_only', false)->orderBy('position')->first()
            : $this->board((int) $data['board_id']);
        if ($board !== null && $board->staff_only && ! $this->isStaff($request)) {
            return response()->json(['message' => 'هذا المنتدى للإدارة فقط، يمكنك الرد على مواضيعه'], 403);
        }

        $id = DB::table('forum_threads')->insertGetId([
            'user_id' => $request->user()->id, 'title' => trim($data['title']), 'body' => trim($data['body']),
            'company_id' => $companyId, 'board_id' => $board?->id, 'last_post_user_id' => $request->user()->id,
        ]);

        return response()->json(['id' => $id], 201);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $body = trim($request->validate(['body' => ['required', 'string', 'max:10000']])['body']);
        abort_if($body === '', 422, 'الرد فارغ');

        return DB::transaction(function () use ($id, $body, $request) {
            $thread = DB::table('forum_threads')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first(['id', 'locked']);
            abort_if($thread === null, 404, 'الموضوع غير موجود');
            abort_if($thread->locked && ! $this->isStaff($request), 403, 'الموضوع مغلق');
            DB::table('forum_threads')->where('id', $id)->update([
                'reply_count' => DB::raw('reply_count + 1'), 'last_post_at' => DB::raw('clock_timestamp()'),
                'last_post_user_id' => $request->user()->id,
            ]);
            $postId = DB::table('forum_posts')->insertGetId(['thread_id' => $id, 'user_id' => $request->user()->id, 'body' => $body]);

            return response()->json(['id' => $postId], 201);
        });
    }

    /**
     * Edit a thread. Its author may change the title and the opening post;
     * staff may also pin, lock and move it.
     */
    public function updateThread(Request $request, int $id): JsonResponse
    {
        $thread = DB::table('forum_threads')->where('id', $id)->whereNull('deleted_at')->first();
        abort_if($thread === null, 404, 'الموضوع غير موجود');
        $staff = $this->isStaff($request);
        abort_unless($staff || $thread->user_id === $request->user()->id, 403, 'لا يمكنك تعديل موضوع غيرك');

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:3', 'max:150'],
            'body' => ['sometimes', 'string', 'min:1', 'max:10000'],
            'pinned' => ['sometimes', 'boolean'],
            'locked' => ['sometimes', 'boolean'],
            'board_id' => ['sometimes', 'integer'],
        ]);
        $moderation = array_intersect_key($data, array_flip(['pinned', 'locked', 'board_id']));
        abort_if($moderation !== [] && ! $staff, 403, 'للمشرفين فقط');
        abort_if($thread->locked && ! $staff, 403, 'الموضوع مغلق');
        if (isset($data['board_id'])) {
            $this->board((int) $data['board_id']);
        }
        if (isset($data['title']) || isset($data['body'])) {
            $data['edited_at'] = DB::raw('now()');
            foreach (['title', 'body'] as $text) {
                if (isset($data[$text])) {
                    $data[$text] = trim($data[$text]);
                }
            }
        }
        DB::table('forum_threads')->where('id', $id)->update($data);
        if ($moderation !== []) {
            $this->audit($request, 'forum.moderate_thread', ['id' => $id] + $moderation);
        }

        return response()->json(['status' => 'updated']);
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

    /** Edit a reply: its author, or staff. */
    public function updatePost(Request $request, int $id): JsonResponse
    {
        $body = trim($request->validate(['body' => ['required', 'string', 'max:10000']])['body']);
        abort_if($body === '', 422, 'الرد فارغ');
        $post = $this->post($id);
        $staff = $this->isStaff($request);
        abort_unless($staff || $post->user_id === $request->user()->id, 403, 'لا يمكنك تعديل رد غيرك');
        abort_if($post->locked && ! $staff, 403, 'الموضوع مغلق');
        DB::table('forum_posts')->where('id', $id)->update(['body' => $body, 'edited_at' => DB::raw('now()')]);

        return response()->json(['status' => 'updated']);
    }

    /** Remove a reply: staff only. The thread's reply count follows. */
    public function deletePost(Request $request, int $id): JsonResponse
    {
        $this->staffOnly($request);
        $post = $this->post($id);
        DB::transaction(function () use ($post, $request) {
            DB::table('forum_posts')->where('id', $post->id)->update(['deleted_at' => DB::raw('now()')]);
            DB::table('forum_threads')->where('id', $post->thread_id)->update(['reply_count' => DB::raw('GREATEST(reply_count - 1, 0)')]);
            $this->audit($request, 'forum.delete_post', ['id' => $post->id, 'thread_id' => $post->thread_id]);
        });

        return response()->json(['status' => 'deleted']);
    }

    /** The member's own signature, shown under each of their posts. */
    public function signature(Request $request): JsonResponse
    {
        $data = $request->validate(['signature' => ['present', 'nullable', 'string', 'max:300']]);
        DB::table('users')->where('id', $request->user()->id)->update(['forum_signature' => trim((string) $data['signature'])]);

        return response()->json(['signature' => trim((string) $data['signature'])]);
    }

    // ------------------------------------------------------------ sections (admin)

    /** Add a category (no parent) or a forum inside a category. */
    public function createBoard(Request $request): JsonResponse
    {
        $data = $this->boardData($request, required: true);
        if (! empty($data['parent_id'])) {
            $parent = DB::table('forum_boards')->where('id', $data['parent_id'])->first();
            abort_if($parent === null || $parent->parent_id !== null, 422, 'القسم الرئيسي غير موجود');
        }
        $data['position'] ??= 1 + (int) DB::table('forum_boards')->where('parent_id', $data['parent_id'] ?? null)->max('position');
        $id = DB::table('forum_boards')->insertGetId($data);
        $this->audit($request, 'forum.create_board', ['id' => $id] + $data);

        return response()->json(['id' => $id], 201);
    }

    public function updateBoard(Request $request, int $id): JsonResponse
    {
        abort_unless(DB::table('forum_boards')->where('id', $id)->exists(), 404, 'القسم غير موجود');
        $data = $this->boardData($request, required: false);
        unset($data['parent_id']);
        DB::table('forum_boards')->where('id', $id)->update($data);
        $this->audit($request, 'forum.update_board', ['id' => $id] + $data);

        return response()->json(['status' => 'updated']);
    }

    /** Remove an empty forum, or a category with no forums in it. */
    public function deleteBoard(Request $request, int $id): JsonResponse
    {
        abort_unless(DB::table('forum_boards')->where('id', $id)->exists(), 404, 'القسم غير موجود');
        abort_if(DB::table('forum_boards')->where('parent_id', $id)->exists(), 422, 'انقل أو احذف منتديات هذا القسم أولًا');
        abort_if(DB::table('forum_threads')->where('board_id', $id)->whereNull('deleted_at')->exists(), 422, 'انقل مواضيع هذا المنتدى أولًا');
        DB::transaction(function () use ($id, $request) {
            DB::table('forum_threads')->where('board_id', $id)->update(['board_id' => null]); // only deleted threads remain
            DB::table('forum_boards')->where('id', $id)->delete();
            $this->audit($request, 'forum.delete_board', ['id' => $id]);
        });

        return response()->json(['status' => 'deleted']);
    }

    // ---------------------------------------------------------- helpers

    private function boardData(Request $request, bool $required): array
    {
        $data = $request->validate([
            'name' => [$required ? 'required' : 'sometimes', 'string', 'min:2', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'position' => ['sometimes', 'integer', 'between:0,1000'],
            'staff_only' => ['sometimes', 'boolean'],
            'parent_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        if (array_key_exists('description', $data)) {
            $data['description'] = (string) $data['description'];
        }

        return $data;
    }

    /** A forum that threads can be posted in (not a category). */
    private function board(int $id): object
    {
        $board = DB::table('forum_boards')->where('id', $id)->whereNotNull('parent_id')->first();
        abort_if($board === null, 404, 'المنتدى غير موجود');

        return $board;
    }

    private function post(int $id): object
    {
        $post = DB::table('forum_posts as p')->join('forum_threads as t', 't.id', '=', 'p.thread_id')
            ->where('p.id', $id)->whereNull('p.deleted_at')->whereNull('t.deleted_at')
            ->first(['p.id', 'p.user_id', 'p.thread_id', 't.locked']);
        abort_if($post === null, 404, 'الرد غير موجود');

        return $post;
    }

    /**
     * What is shown beside each post: role, join date, post count, rank and
     * signature of the given users.
     *
     * @return array<int, array<string, mixed>>
     */
    private function authors(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $rows = DB::table('users as u')->whereIn('u.id', $userIds)->get([
            'u.id', 'u.alias', 'u.role', 'u.created_at', 'u.forum_signature',
            DB::raw('(SELECT count(*) FROM forum_threads WHERE user_id = u.id AND deleted_at IS NULL)
                   + (SELECT count(*) FROM forum_posts WHERE user_id = u.id AND deleted_at IS NULL) AS posts'),
        ]);
        $authors = [];
        foreach ($rows as $r) {
            $authors[$r->id] = [
                'alias' => $r->alias, 'role' => $r->role, 'joined_at' => $r->created_at,
                'posts' => (int) $r->posts, 'rank' => self::rank($r->role, (int) $r->posts), 'signature' => $r->forum_signature,
            ];
        }

        return $authors;
    }

    private static function rank(string $role, int $posts): string
    {
        return match (true) {
            $role === 'admin' => 'مدير الموقع',
            $role === 'moderator' => 'مشرف',
            $posts >= 1000 => 'عضو ماسي',
            $posts >= 300 => 'عضو ذهبي',
            $posts >= 100 => 'عضو مميز',
            $posts >= 20 => 'عضو نشط',
            default => 'عضو جديد',
        };
    }

    private function isStaff(Request $request): bool
    {
        return in_array($request->user()->role, ['admin', 'moderator'], true);
    }

    private function staffOnly(Request $request): void
    {
        abort_unless($this->isStaff($request), 403, 'للمشرفين فقط');
    }

    private function audit(Request $request, string $action, array $details): void
    {
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE), 'ip' => $request->ip()]);
    }
}
