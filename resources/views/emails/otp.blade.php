@php
    // Branded layout shared with every eBizEarn email (App\Services\Email\EmailLayout).
    $name = e(\Illuminate\Support\Str::of($userName)->explode(' ')->first() ?: 'there');
    echo \App\Services\Email\EmailLayout::render(array_merge(\App\Services\Email\EmailLayout::variables(), [
        'app_name' => e(config('app.name')),
        'support_email' => e(config('platform.supportEmail')),
        'preheader' => 'Your verification code is ' . e($code) . '. It expires in ' . e($ttlMinutes) . ' minutes.',
        'eyebrow' => 'Email verification',
        'title' => 'Your verification code',
        'subtitle' => 'Enter this code to activate your eBizEarn account.',
        'greeting' => 'Hi ' . $name . ',',
        'paragraphs' => ['Use the 6-digit code below to verify your email address:'],
        'code' => e($code),
        'details' => [['Expires in', e($ttlMinutes) . ' minutes']],
        'note' => 'Never share this code with anyone — eBizEarn staff will never ask for it. Didn’t sign up? You can safely ignore this email.',
    ]));
@endphp
