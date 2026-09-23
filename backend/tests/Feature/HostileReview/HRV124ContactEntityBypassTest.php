<?php

declare(strict_types=1);

namespace Tests\Feature\HostileReview;

use Illuminate\Support\Facades\DB;
use Tests\Feature\HR232ContactEntityScopingTest;

/**
 * Hostile review repro (#124/#232): an Entity-A-scoped user must not reach an
 * Entity-B contact through search / show / 360 / profiles.
 */
class HRV124ContactEntityBypassTest extends HR232ContactEntityScopingTest
{
    public function test_entity_a_user_reaches_entity_b_contact_outside_index(): void
    {
        $entityA = \Closure::bind(fn () => $this->entityA, $this, HR232ContactEntityScopingTest::class)();
        $user = \Closure::bind(fn () => $this->actingAsViewer($entityA), $this, HR232ContactEntityScopingTest::class)();
        $contactB = DB::table('core_contacts')->where('display_name', 'Contact B')->value('id');

        $results = [];
        $search = $this->actingAs($user)->getJson('/api/v1/contacts/search?q=Contact');
        $results['search'] = $search->getStatusCode().' names='.json_encode(collect($search->json('data.data') ?? $search->json('data'))->pluck('display_name')->all());
        foreach (['show' => "/api/v1/contacts/{$contactB}", '360' => "/api/v1/contacts/{$contactB}/360", 'profiles' => "/api/v1/contacts/{$contactB}/profiles"] as $k => $url) {
            $results[$k] = $this->actingAs($user)->getJson($url)->getStatusCode();
        }

        fwrite(STDERR, "\n[HRV124] ".json_encode($results)."\n");

        $this->assertNotSame(200, $results['show'], 'Entity-A user must not read an Entity-B contact by id');
    }
}
