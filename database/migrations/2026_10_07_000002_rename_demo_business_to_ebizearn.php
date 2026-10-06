<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The demo business account was seeded as "Acme Growth Labs"
 * (brand@acme.com). Rename it to the real company, eBizEarn, everywhere it
 * is stored: login email, company name, website, billing email, and any
 * campaign / task branding copied from it. Matches only the known seeded
 * values so no real customer data is touched.
 */
return new class extends Migration
{
    private const OLD_EMAIL = 'brand@acme.com';
    private const NEW_EMAIL = 'brand@ebizearn.com';
    private const OLD_COMPANY = 'Acme Growth Labs';
    private const NEW_COMPANY = 'eBizEarn';

    public function up(): void
    {
        $this->rename(self::OLD_EMAIL, self::NEW_EMAIL, self::OLD_COMPANY, self::NEW_COMPANY, 'https://acme.example.com', 'https://ebizearn.com', 'billing@acme.example.com', 'billing@ebizearn.com');
    }

    public function down(): void
    {
        $this->rename(self::NEW_EMAIL, self::OLD_EMAIL, self::NEW_COMPANY, self::OLD_COMPANY, 'https://ebizearn.com', 'https://acme.example.com', 'billing@ebizearn.com', 'billing@acme.example.com');
    }

    private function rename(string $fromEmail, string $toEmail, string $fromCompany, string $toCompany, string $fromSite, string $toSite, string $fromBilling, string $toBilling): void
    {
        DB::transaction(function () use ($fromEmail, $toEmail, $fromCompany, $toCompany, $fromSite, $toSite, $fromBilling, $toBilling) {
            // Never collide with an account that already owns the new email.
            if (!DB::table('users')->where('email', $toEmail)->exists()) {
                DB::table('users')->where('email', $fromEmail)->update(['email' => $toEmail]);
            }

            DB::table('businesses')->where('company_name', $fromCompany)->update(['company_name' => $toCompany]);
            DB::table('businesses')->where('website', $fromSite)->update(['website' => $toSite]);
            DB::table('businesses')->where('billing_email', $fromBilling)->update(['billing_email' => $toBilling]);
            DB::table('campaigns')->where('company_name', $fromCompany)->update(['company_name' => $toCompany]);
            DB::table('tasks')->where('company_name', $fromCompany)->update(['company_name' => $toCompany]);
        });
    }
};
