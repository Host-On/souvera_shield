<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Tests\Unit\Service\Reputation;

use OCA\SouveraShield\Service\ProviderToolsClient;
use OCA\SouveraShield\Service\Reputation\DmarcInsightService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DmarcInsightServiceTest extends TestCase {

    private function service(): DmarcInsightService {
        return new DmarcInsightService(
            $this->createMock(ProviderToolsClient::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testProviderBreakdownBucketsReportersCorrectly(): void {
        $now = time();
        $reports = [
            ['orgName' => 'google.com',   'totalMessages' => 100, 'passedDkim' => 98, 'passedSpf' => 95, 'dateRangeEnd' => $now],
            ['orgName' => 'Outlook.com',  'totalMessages' => 50,  'passedDkim' => 25, 'passedSpf' => 20, 'dateRangeEnd' => $now],
            ['orgName' => 'Yahoo',        'totalMessages' => 10,  'passedDkim' => 10, 'passedSpf' => 10, 'dateRangeEnd' => $now],
            ['orgName' => 'GMX Net Mail', 'totalMessages' => 20,  'passedDkim' => 20, 'passedSpf' => 19, 'dateRangeEnd' => $now],
            ['orgName' => 'Mystery Corp', 'totalMessages' => 5,   'passedDkim' => 5,  'passedSpf' => 5,  'dateRangeEnd' => $now],
        ];
        $buckets = [];
        foreach ($this->service()->providerBreakdown($reports) as $p) {
            $buckets[$p['key']] = $p;
        }

        $this->assertSame(100, $buckets['google']['messages']);
        $this->assertSame(98, $buckets['google']['dkimPassRate']);
        $this->assertSame('ok', $buckets['google']['verdict']);

        $this->assertSame(50, $buckets['microsoft']['messages']);
        $this->assertSame('critical', $buckets['microsoft']['verdict']);

        $this->assertSame(10, $buckets['yahoo']['messages']);
        $this->assertSame(20, $buckets['gmx_webde']['messages']);
        $this->assertSame(5, $buckets['other']['messages']);
        $this->assertContains('Mystery Corp', $buckets['other']['orgNames']);
    }

    public function testProviderWithoutReportsReportsNoData(): void {
        $buckets = [];
        foreach ($this->service()->providerBreakdown([]) as $p) {
            $buckets[$p['key']] = $p;
        }
        $this->assertSame('nodata', $buckets['google']['verdict']);
        $this->assertNull($buckets['google']['dkimPassRate']);
        $this->assertSame(0, $buckets['google']['messages']);
    }

    public function testClassifySourcesLegitUnknownAbusive(): void {
        $stats = ['topSenders' => [
            ['organization' => 'Souvera Stalwart', 'messages' => 400, 'dkimPassRate' => 99, 'spfPassRate' => 97],
            ['organization' => 'Forwarder Inc',    'messages' => 30,  'dkimPassRate' => 60, 'spfPassRate' => 5],
            ['organization' => 'SpoofBot',         'messages' => 50,  'dkimPassRate' => 0,  'spfPassRate' => 0],
            ['organization' => 'TinySender',       'messages' => 2,   'dkimPassRate' => 0,  'spfPassRate' => 0],
        ]];
        $sources = $this->service()->classifySources($stats);
        $byOrg = [];
        foreach ($sources as $s) {
            $byOrg[$s['organization']] = $s;
        }
        $this->assertSame('legitimate', $byOrg['Souvera Stalwart']['classification']);
        $this->assertSame('unknown', $byOrg['Forwarder Inc']['classification']);
        $this->assertSame('abusive', $byOrg['SpoofBot']['classification']);
        // Too little volume to call it abuse.
        $this->assertSame('unknown', $byOrg['TinySender']['classification']);
        // Sorted by volume descending.
        $this->assertSame('Souvera Stalwart', $sources[0]['organization']);
    }

    public function testDetectAnomaliesFindsVolumeSpike(): void {
        $reports = [];
        $base = strtotime('2026-06-01 00:00:00');
        for ($i = 0; $i < 10; $i++) {
            $reports[] = [
                'orgName' => 'google.com',
                'totalMessages' => 40,
                'dateRangeBegin' => $base + $i * 86400,
            ];
        }
        // Day 11: massive burst.
        $reports[] = ['orgName' => 'google.com', 'totalMessages' => 5000, 'dateRangeBegin' => $base + 10 * 86400];

        $anomalies = $this->service()->detectAnomalies($reports, []);
        $spikes = array_values(array_filter($anomalies, static fn(array $a) => $a['type'] === 'volume_spike'));
        $this->assertCount(1, $spikes);
        $this->assertSame(5000, $spikes[0]['messages']);
        $this->assertSame('2026-06-11', $spikes[0]['day']);
    }

    public function testDetectAnomaliesQuietBaselineHasNoSpike(): void {
        $reports = [];
        $base = strtotime('2026-06-01 00:00:00');
        for ($i = 0; $i < 10; $i++) {
            $reports[] = ['orgName' => 'google.com', 'totalMessages' => 40 + $i, 'dateRangeBegin' => $base + $i * 86400];
        }
        $anomalies = $this->service()->detectAnomalies($reports, []);
        $this->assertSame([], array_filter($anomalies, static fn(array $a) => $a['type'] === 'volume_spike'));
    }

    public function testDetectAnomaliesFlagsAbusiveSources(): void {
        $stats = ['topSenders' => [
            ['organization' => 'SpoofBot', 'messages' => 120, 'dkimPassRate' => 0, 'spfPassRate' => 2],
        ]];
        $anomalies = $this->service()->detectAnomalies([], $stats);
        $this->assertCount(1, $anomalies);
        $this->assertSame('abusive_source', $anomalies[0]['type']);
        $this->assertSame('SpoofBot', $anomalies[0]['organization']);
    }
}
