<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * In-app support tickets. Contributors and businesses open and reply to
 * their own tickets (/support/tickets); staff with handle_disputes work the
 * shared queue (/staff/support/tickets). Internal notes are staff-only.
 */
class SupportTicketController extends Controller
{
    public const CATEGORIES = ['payout', 'dispute', 'social', 'bug', 'account', 'kyc', 'business', 'general'];
    private const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    // ------------------------------------------------------------------
    // User side
    // ------------------------------------------------------------------

    /** GET /support/tickets */
    public function index(Request $request): JsonResponse
    {
        $tickets = SupportTicket::where('user_id', $request->user()->id)
            ->with('firstMessage')
            ->withCount(['messages' => fn ($q) => $q->where('is_internal_note', false)])
            ->latest('updated_at')
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $tickets->map(fn ($t) => $this->present($t, false))->values(),
        ]);
    }

    /** POST /support/tickets  { subject, category, message, priority? } */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|string|min:3|max:191',
            'category' => 'required|string|in:' . implode(',', self::CATEGORIES),
            'message' => 'required|string|min:5|max:5000',
            'priority' => 'nullable|in:low,normal,high',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        $ticket = DB::transaction(function () use ($request, $user) {
            $ticket = SupportTicket::create([
                'user_id' => $user->id,
                'subject' => trim($request->input('subject')),
                'category' => $request->input('category'),
                'priority' => $request->input('priority', 'normal'),
                'status' => 'open',
            ]);

            SupportMessage::create([
                'ticket_id' => $ticket->id,
                'sender_id' => $user->id,
                'message' => trim($request->input('message')),
                'is_internal_note' => false,
            ]);

            return $ticket;
        });

        return response()->json([
            'success' => true,
            'message' => 'Ticket submitted. Our support team will reply here.',
            'data' => $this->present($this->loadDetail($ticket, false), false, true),
        ], 201);
    }

    /** GET /support/tickets/{uuid} */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $ticket = $this->ownTicket($request, $uuid);

        return response()->json([
            'success' => true,
            'data' => $this->present($this->loadDetail($ticket, false), false, true),
        ]);
    }

    /** POST /support/tickets/{uuid}/messages  { message } */
    public function reply(Request $request, string $uuid): JsonResponse
    {
        $ticket = $this->ownTicket($request, $uuid);

        if ($ticket->status === 'closed') {
            return response()->json([
                'success' => false,
                'message' => 'This ticket is closed. Please open a new ticket.',
            ], 422);
        }

        $validator = Validator::make($request->all(), ['message' => 'required|string|min:1|max:5000']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        SupportMessage::create([
            'ticket_id' => $ticket->id,
            'sender_id' => $request->user()->id,
            'message' => trim($request->input('message')),
            'is_internal_note' => false,
        ]);

        // A user reply re-opens a resolved ticket so staff see it again.
        $ticket->status = $ticket->status === 'resolved' ? 'open' : $ticket->status;
        $ticket->touch();

        return response()->json([
            'success' => true,
            'data' => $this->present($this->loadDetail($ticket, false), false, true),
        ]);
    }

    // ------------------------------------------------------------------
    // Staff side
    // ------------------------------------------------------------------

    /** GET /staff/support/tickets?status=&search= */
    public function staffIndex(Request $request): JsonResponse
    {
        $query = SupportTicket::query()
            ->with(['user:id,name,email,role', 'assignedAgent:id,name', 'firstMessage'])
            ->withCount('messages');

        $status = $request->input('status', 'all');
        if ($status === 'active') {
            $query->whereIn('status', ['open', 'in_progress']);
        } elseif (in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $like = '%' . $term . '%';
            $query->where(function ($q) use ($like, $term) {
                $q->where('subject', 'like', $like)
                    ->orWhere('uuid', 'like', $like)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like));
                if (preg_match('/^(?:TKT-)?0*(\d+)$/i', $term, $m)) {
                    $q->orWhere('id', (int) $m[1]);
                }
            });
        }

        $page = $query->latest('updated_at')->paginate(25);

        $counts = SupportTicket::select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn ($t) => $this->present($t, true))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'counts' => [
                    'open' => (int) ($counts['open'] ?? 0),
                    'in_progress' => (int) ($counts['in_progress'] ?? 0),
                    'resolved' => (int) ($counts['resolved'] ?? 0),
                    'closed' => (int) ($counts['closed'] ?? 0),
                ],
            ],
        ]);
    }

    /** GET /staff/support/tickets/{uuid} */
    public function staffShow(string $uuid): JsonResponse
    {
        $ticket = SupportTicket::where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $this->present($this->loadDetail($ticket, true), true, true),
        ]);
    }

    /** POST /staff/support/tickets/{uuid}/messages  { message, internal? } */
    public function staffReply(Request $request, string $uuid): JsonResponse
    {
        $ticket = SupportTicket::where('uuid', $uuid)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|min:1|max:5000',
            'internal' => 'sometimes|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $internal = $request->boolean('internal');

        SupportMessage::create([
            'ticket_id' => $ticket->id,
            'sender_id' => $request->user()->id,
            'message' => trim($request->input('message')),
            'is_internal_note' => $internal,
        ]);

        if (!$internal && $ticket->status === 'open') {
            $ticket->status = 'in_progress';
        }
        if (!$ticket->assigned_agent_id) {
            $ticket->assigned_agent_id = $request->user()->id;
        }
        $ticket->touch();

        return response()->json([
            'success' => true,
            'message' => $internal ? 'Internal note added.' : 'Reply sent.',
            'data' => $this->present($this->loadDetail($ticket, true), true, true),
        ]);
    }

    /** PATCH /staff/support/tickets/{uuid}  { status?, priority? } */
    public function staffUpdate(Request $request, string $uuid): JsonResponse
    {
        $ticket = SupportTicket::where('uuid', $uuid)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|in:' . implode(',', self::STATUSES),
            'priority' => 'sometimes|in:' . implode(',', self::PRIORITIES),
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $changes = $validator->validated();
        if ($changes === []) {
            return response()->json(['success' => false, 'message' => 'Nothing to update.'], 422);
        }

        $before = $ticket->only(array_keys($changes));
        $ticket->fill($changes);
        if (!$ticket->assigned_agent_id) {
            $ticket->assigned_agent_id = $request->user()->id;
        }
        $ticket->save();

        AuditLogger::log($request->user(), 'support_ticket.updated', SupportTicket::class, $ticket->id, [], $before, $changes);

        return response()->json([
            'success' => true,
            'message' => 'Ticket updated.',
            'data' => $this->present($this->loadDetail($ticket, true), true, true),
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function ownTicket(Request $request, string $uuid): SupportTicket
    {
        return SupportTicket::where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }

    private function loadDetail(SupportTicket $ticket, bool $staff): SupportTicket
    {
        return $ticket->fresh()->load([
            'user:id,name,email,role',
            'assignedAgent:id,name',
            'firstMessage',
            'messages' => fn ($q) => $staff ? $q : $q->where('is_internal_note', false),
            'messages.sender:id,name,role',
        ]);
    }

    private function present(SupportTicket $t, bool $staff, bool $withMessages = false): array
    {
        $data = [
            'id' => $t->id,
            'uuid' => $t->uuid,
            'reference' => 'TKT-' . str_pad((string) $t->id, 5, '0', STR_PAD_LEFT),
            'subject' => $t->subject,
            'category' => $t->category,
            'priority' => $t->priority,
            'status' => $t->status,
            'description' => $t->firstMessage?->message,
            'messages_count' => $t->messages_count ?? ($t->relationLoaded('messages') ? $t->messages->count() : null),
            'assigned_agent' => $t->assignedAgent ? ['id' => $t->assignedAgent->id, 'name' => $t->assignedAgent->name] : null,
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];

        if ($staff) {
            $data['user'] = $t->user ? $t->user->only(['id', 'name', 'email', 'role']) : null;
        }

        if ($withMessages) {
            $data['messages'] = $t->messages->map(fn (SupportMessage $m) => [
                'id' => $m->id,
                'message' => $m->message,
                'is_internal_note' => (bool) $m->is_internal_note,
                'from_staff' => $m->sender_id !== $t->user_id,
                'sender_name' => $m->sender_id === $t->user_id
                    ? ($m->sender?->name ?? 'You')
                    : ($staff ? ($m->sender?->name ?? 'Staff') : 'eBizEarn Support'),
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values();
        }

        return $data;
    }
}
