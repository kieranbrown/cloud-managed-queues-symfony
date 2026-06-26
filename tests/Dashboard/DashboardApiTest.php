<?php

namespace App\Tests\Dashboard;

use App\Dashboard\DashboardStore;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DashboardApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private DashboardStore $store;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->store = static::getContainer()->get(DashboardStore::class);
        $this->store->ensureSchema();
        $this->store->reset();
        $this->store->resetWorkers();
    }

    public function testDispatchInsertsMetricsAndRunsJobsSynchronously(): void
    {
        // sync transport (test env) runs the handler inline, so jobs complete.
        $this->client->request('POST', '/api/dispatch', content: json_encode([
            'count' => 3,
            'min_duration' => 0,
            'max_duration' => 0,
            'queue' => 'processing',
        ]));

        $this->assertResponseIsSuccessful();
        $batchId = json_decode($this->client->getResponse()->getContent(), true)['batchId'];
        $this->assertNotEmpty($batchId);

        $this->client->request('GET', '/api/state?batch='.$batchId);
        $this->assertResponseIsSuccessful();
        $state = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertSame(3, $state['stats']['total']);
        $this->assertSame(3, $state['stats']['completed']);
        $this->assertSame(0, $state['stats']['pending']);
        $this->assertCount(3, $state['jobs']);
        $this->assertSame('completed', $state['jobs'][0]['status']);
        $this->assertSame('processing', $state['jobs'][0]['queue']);
        $this->assertNotNull($state['jobs'][0]['worker']);
    }

    public function testResetWipesMetricsButRecentBatchesReflectIt(): void
    {
        $this->client->request('POST', '/api/dispatch', content: json_encode([
            'count' => 2, 'min_duration' => 0, 'max_duration' => 0, 'queue' => 'default',
        ]));
        $this->client->request('POST', '/api/reset');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/api/state');
        $state = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertSame([], $state['recentBatches']);
        $this->assertSame(0, $state['stats']['total']);
    }

    public function testDashboardPageRenders(): void
    {
        $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
    }
}
