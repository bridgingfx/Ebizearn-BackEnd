<?php

namespace Tests\Unit;

use App\Models\Campaign;
use PHPUnit\Framework\TestCase;

/**
 * The task feed only matches 'ALL' or an uppercase ISO code, so every
 * "worldwide" spelling must collapse to ['ALL'].
 */
class CampaignTargetCountriesTest extends TestCase
{
    public function test_worldwide_spellings_become_all(): void
    {
        foreach ([['GLOBAL'], ['global'], ['ALL'], ['Worldwide'], [], [''], null, ['AE', 'GLOBAL']] as $input) {
            $this->assertSame(['ALL'], Campaign::normalizeTargetCountries($input), json_encode($input));
        }
    }

    public function test_country_codes_are_uppercased_and_deduplicated(): void
    {
        $this->assertSame(['AE', 'IN'], Campaign::normalizeTargetCountries(['ae', ' AE ', 'in']));
    }
}
