<?php

namespace Tests\Feature;

use App\Services\AI\ContentGeneratorService;
use App\Services\AI\AiSettings;
use App\Services\AI\ContentSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Campaign content contributors copy and post must be clean: offensive
 * words are caught (even disguised), normal marketing copy is not, and the
 * AI generator only ever returns clean, copy-ready post text.
 */
class ContentSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function chat(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content]]]];
    }

    public function test_offensive_words_are_caught_even_when_disguised(): void
    {
        config(['services.openai.key' => null]);
        $safety = app(ContentSafety::class);

        foreach (['This is shit', 'what the f*ck', 'sh1t deal', 'FUUUCK yes', 'you idiot!', 'nice-idiot', 'a$$hole', 'enna punda'] as $text) {
            $this->assertFalse($safety->check($text)['ok'], "Should block: {$text}");
        }
    }

    public function test_normal_marketing_copy_is_not_blocked(): void
    {
        config(['services.openai.key' => null]);
        $safety = app(ContentSafety::class);

        foreach ([
            'Book your photo shoot today!',
            "Visit Bob's Bakery for fresh bread",
            'Kills 99% of germs on contact',
            'Crispy crackers and shiitake soup',
            'Assess your class progress in Scunthorpe',
            'Come hang out with us this weekend #fun',
        ] as $text) {
            $this->assertTrue($safety->check($text)['ok'], "Should allow: {$text}");
        }
    }

    public function test_offensive_brief_is_rejected_before_calling_the_ai(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake();

        $result = app(ContentGeneratorService::class)->generate('instagram', 'Tell people our rivals are idiots');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('idiot', $result['message']);
        Http::assertNothingSent();
    }

    public function test_generated_content_is_only_the_clean_post_text(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/moderations' => Http::response(['results' => [['flagged' => false, 'categories' => []]]]),
            'api.openai.com/v1/chat/completions' => Http::response($this->chat("Here's your post:\n\"Fresh coffee every morning at **Bean House**! #coffee #morning\"")),
        ]);

        $result = app(ContentGeneratorService::class)->generate('instagram', 'Promote our new coffee shop');

        $this->assertTrue($result['success']);
        $this->assertSame('Fresh coffee every morning at Bean House! #coffee #morning', $result['content']);
    }

    public function test_offensive_ai_output_is_never_returned(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/moderations' => Http::response(['results' => [['flagged' => false, 'categories' => []]]]),
            'api.openai.com/v1/chat/completions' => Http::response($this->chat('This coffee is damn good, no bullshit #coffee')),
        ]);

        $result = app(ContentGeneratorService::class)->generate('instagram', 'Promote our new coffee shop');

        $this->assertFalse($result['success']);
        $this->assertNull($result['content']);
        // One retry with stricter settings before giving up.
        Http::assertSentCount(3); // brief moderation + 2 generations (blocked words skip moderation)
    }

    public function test_moderation_flag_blocks_content(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/moderations' => Http::sequence()
                ->push(['results' => [['flagged' => false, 'categories' => []]]])
                ->push(['results' => [['flagged' => true, 'categories' => ['harassment' => true]]]])
                ->push(['results' => [['flagged' => true, 'categories' => ['harassment' => true]]]]),
            'api.openai.com/v1/chat/completions' => Http::response($this->chat('Some rude text')),
        ]);
        $result = app(ContentGeneratorService::class)->generate('facebook', 'Promote our gym');
        $this->assertFalse($result['success']);
        $this->assertNull($result['content']);
    }

    public function test_ai_refusal_is_explained(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/moderations' => Http::response(['results' => [['flagged' => false, 'categories' => []]]]),
            'api.openai.com/v1/chat/completions' => Http::response($this->chat('UNSAFE_REQUEST')),
        ]);
        $result = app(ContentGeneratorService::class)->generate('facebook', 'Promote our gym');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('appropriate', $result['message']);
    }
}
