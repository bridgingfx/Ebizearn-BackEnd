<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing email campaigns (Super Admin → Email → Campaigns). A campaign is
 * sent in small batches through the active email provider; `last_user_id` is
 * the cursor so sending can resume safely without a queue worker. Users can
 * opt out via the unsubscribe link (users.marketing_unsubscribed_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('subject', 255);
            $table->string('heading', 255)->nullable();
            $table->text('body');
            $table->string('button_label', 60)->nullable();
            $table->string('button_url', 500)->nullable();
            $table->string('audience', 20); // all | contributors | businesses
            $table->string('status', 20)->default('draft'); // draft | sending | sent | cancelled
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedBigInteger('last_user_id')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('marketing_unsubscribed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaigns');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('marketing_unsubscribed_at');
        });
    }
};
