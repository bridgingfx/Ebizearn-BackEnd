@php
    // Branded layout shared with every eBizEarn email (App\Services\Email\EmailLayout).
    $name = e(\Illuminate\Support\Str::of($userName)->explode(' ')->first() ?: 'there');
    echo \App\Services\Email\EmailLayout::render(array_merge(\App\Services\Email\EmailLayout::variables(), [
        'app_name' => e(config('app.name')),
        'support_email' => e(config('platform.supportEmail')),
        'preheader' => 'Confirm your email to unlock your eBizEarn dashboard.',
        'eyebrow' => 'Account security',
        'title' => 'Confirm your email address',
        'subtitle' => 'You are one step away from your earning dashboard.',
        'greeting' => 'Hi ' . $name . ',',
        'paragraphs' => ['Please confirm this email address belongs to you so we can keep your account and earnings safe.'],
        'button' => ['Verify my email', e($verifyUrl)],
        'note' => 'This link expires in 24 hours. Didn’t create an eBizEarn account? You can safely ignore this email.',
    ]));
@endphp
