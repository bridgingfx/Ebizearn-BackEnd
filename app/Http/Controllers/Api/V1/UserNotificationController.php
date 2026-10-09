<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-app notifications for contributors and businesses (Laravel database
 * notifications): new follower, follow back, new task from a business
 * whose bell you turned on.
 */
class UserNotificationController extends Controller
{
    /** GET /notifications */
    public function index(Request $request): JsonResponse
    {
        $page = $request->user()->notifications()->paginate(max(1, min(100, (int) $request->input('per_page', 30))));

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn ($n) => [
                'id' => $n->id,
                'kind' => $n->data['kind'] ?? 'info',
                'title' => $n->data['title'] ?? 'Notification',
                'body' => $n->data['body'] ?? '',
                'link' => $n->data['link'] ?? null,
                'data' => $n->data,
                'read_at' => $n->read_at,
                'created_at' => $n->created_at,
            ]),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'unread' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    /** GET /notifications/unread-count */
    public function unread(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['unread' => $request->user()->unreadNotifications()->count()]]);
    }

    /** POST /notifications/read { ids?: string[] } — no ids marks everything read. */
    public function markRead(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => 'nullable|array|max:200', 'ids.*' => 'string|max:64']);
        $query = $request->user()->unreadNotifications();
        if (!empty($data['ids'])) {
            $query->whereIn('id', $data['ids']);
        }
        $query->update(['read_at' => now()]);

        return $this->unread($request);
    }
}
