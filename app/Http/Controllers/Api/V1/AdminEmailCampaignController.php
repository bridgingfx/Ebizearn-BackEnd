<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailCampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Super Admin → Email → Campaigns: marketing emails sent through the active
 * email provider, in batches (the admin page calls /send until done).
 */
class AdminEmailCampaignController extends Controller
{
    public function __construct(private EmailCampaignService $campaigns)
    {
    }

    public function index(): JsonResponse
    {
        return $this->ok(EmailCampaign::orderByDesc('id')->limit(50)->get());
    }

    public function audienceCounts(): JsonResponse
    {
        return $this->ok(collect(EmailCampaign::AUDIENCES)
            ->mapWithKeys(fn ($a) => [$a => $this->campaigns->audienceQuery($a)->count()]));
    }

    public function store(Request $request): JsonResponse
    {
        $campaign = EmailCampaign::create($this->validated($request) + [
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        $this->audit('email_campaign.created', $campaign);

        return $this->ok($campaign, 'Campaign saved as draft.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $campaign = EmailCampaign::findOrFail($id);
        abort_unless($campaign->status === 'draft', 422, 'Only draft campaigns can be edited.');

        $campaign->update($this->validated($request));

        return $this->ok($campaign->fresh(), 'Campaign updated.');
    }

    public function destroy(int $id): JsonResponse
    {
        $campaign = EmailCampaign::findOrFail($id);
        abort_if($campaign->status === 'sending', 422, 'Cancel the campaign before deleting it.');

        $this->audit('email_campaign.deleted', $campaign);
        $campaign->delete();

        return $this->ok(null, 'Campaign deleted.');
    }

    public function test(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['to' => 'required|email']);
        $campaign = EmailCampaign::findOrFail($id);

        $error = null;
        $ok = $this->campaigns->sendTest($campaign, $data['to'], (string) $request->user()->name, $error);

        return response()->json([
            'success' => $ok,
            'message' => $ok ? 'Test email sent to ' . $data['to'] . '.' : 'Test failed: ' . $error,
        ], $ok ? 200 : 422);
    }

    /**
     * Send the next batch. The admin page keeps calling this until status = sent.
     */
    public function send(int $id): JsonResponse
    {
        $campaign = EmailCampaign::findOrFail($id);
        abort_if($campaign->status === 'cancelled', 422, 'This campaign was cancelled.');

        if ($campaign->status === 'draft') {
            abort_if($this->campaigns->audienceQuery($campaign->audience)->doesntExist(), 422, 'No subscribed users in this audience.');
            $this->audit('email_campaign.sent', $campaign);
        }

        $campaign = $this->campaigns->sendBatch($campaign);

        return $this->ok($campaign, $campaign->status === 'sent' ? 'Campaign sent.' : 'Sending…');
    }

    public function cancel(int $id): JsonResponse
    {
        $campaign = EmailCampaign::findOrFail($id);
        abort_unless(in_array($campaign->status, ['draft', 'sending'], true), 422, 'This campaign has already finished.');

        $campaign->update(['status' => 'cancelled', 'completed_at' => now()]);
        $this->audit('email_campaign.cancelled', $campaign);

        return $this->ok($campaign->fresh(), 'Campaign cancelled.');
    }

    /**
     * Public: one-click unsubscribe from the link in a campaign email (signed URL).
     */
    public function unsubscribe(Request $request, int $user): Response
    {
        $account = User::find($user);
        if ($account && !$account->marketing_unsubscribed_at) {
            $account->forceFill(['marketing_unsubscribed_at' => now()])->save();
        }

        $app = e((string) config('app.name'));
        $home = e(rtrim((string) config('platform.frontendUrl'), '/') ?: '/');

        return response(
            '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Unsubscribed</title></head>'
            . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#F1F5F9;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif;">'
            . '<div style="max-width:420px;margin:16px;padding:32px;background:#fff;border-radius:18px;text-align:center;">'
            . '<h1 style="margin:0 0 10px;font-size:22px;color:#07182F;">You are unsubscribed</h1>'
            . '<p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#475569;">You will no longer receive marketing emails from ' . $app . '. Account and security emails will still be sent.</p>'
            . '<a href="' . $home . '" style="display:inline-block;padding:11px 22px;border-radius:12px;background:#168BFF;color:#fff;font-weight:700;text-decoration:none;">Back to ' . $app . '</a>'
            . '</div></body></html>'
        )->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'subject' => 'required|string|max:255',
            // A campaign uses either a custom template as its design, or a written message.
            'template_key' => ['nullable', 'string', Rule::exists('email_templates', 'event_key')->where('is_custom', true)],
            'heading' => 'nullable|string|max:255',
            'body' => 'required_without:template_key|nullable|string|max:20000',
            'button_label' => 'nullable|string|max:60|required_with:button_url',
            'button_url' => 'nullable|url|max:500|required_with:button_label',
            'audience' => ['required', Rule::in(EmailCampaign::AUDIENCES)],
        ], [
            'body.required_without' => 'Write a message, or choose a custom template as the design.',
            'template_key.exists' => 'Choose one of your custom templates.',
        ]);
        $data['body'] = $data['body'] ?? '';

        return $data;
    }

    private function audit(string $action, EmailCampaign $campaign): void
    {
        AuditLogger::log(request()->user(), $action, EmailCampaign::class, $campaign->id, [
            'name' => $campaign->name,
            'audience' => $campaign->audience,
        ]);
    }

    private function ok($data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
