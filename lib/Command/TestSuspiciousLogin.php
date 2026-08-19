<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Command;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Service\IpEnrichmentService;
use OCA\SouveraShield\Service\SuspiciousLoginRules;
use OCA\SouveraShield\Service\CentralSettings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Test the Suspicious Login Detection rules engine against a simulated login.
 *
 *   occ souvera_shield:suspicious-login:test --user=falk --ip=5.6.7.8
 *
 * Simulates a login from the given IP for the given user, enriches the IP,
 * and runs all 11 rules to show the resulting score, severity, and decision
 * without persisting anything.
 */
class TestSuspiciousLogin extends Command {

    public function __construct(
        private readonly IpEnrichmentService $ipEnrichment,
        private readonly SuspiciousLoginRules $rules,
        private readonly CentralSettings $central,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('souvera_shield:suspicious-login:test')
            ->setDescription('Test suspicious login scoring against a simulated login.')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'User ID to simulate login for')
            ->addOption('ip', null, InputOption::VALUE_REQUIRED, 'IP address to simulate login from');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $userId = $input->getOption('user');
        $ip = $input->getOption('ip');

        if ($userId === null || $userId === '' || $ip === null || $ip === '') {
            $io->error('Both --user and --ip are required.');
            return Command::FAILURE;
        }

        $io->title('Suspicious Login Detection – Simulation');
        $io->section('Input');
        $io->definitionList(
            ['User'  => $userId],
            ['IP'    => $ip],
            ['Time'  => date('Y-m-d H:i:s T')],
        );

        $io->section('IP Enrichment');
        try {
            $enrichment = $this->ipEnrichment->enrich($ip);
            $io->definitionList(
                ['Country' => $enrichment['country'] ?? 'unknown'],
                ['City'    => $enrichment['city'] ?? 'unknown'],
                ['ISP'     => $enrichment['isp'] ?? 'unknown'],
                ['ASN'     => $enrichment['asn'] ?? 'unknown'],
                ['Hosting' => isset($enrichment['hosting']) ? ($enrichment['hosting'] ? 'YES' : 'no') : 'unknown'],
                ['VPN'     => isset($enrichment['vpn']) ? ($enrichment['vpn'] ? 'YES' : 'no') : 'unknown'],
                ['Proxy'   => isset($enrichment['proxy']) ? ($enrichment['proxy'] ? 'YES' : 'no') : 'unknown'],
                ['Tor'     => isset($enrichment['tor']) ? ($enrichment['tor'] ? 'YES' : 'no') : 'unknown'],
                ['Blocklisted' => !empty($enrichment['blocklists']) ? 'YES (' . implode(', ', $enrichment['blocklists']) . ')' : 'no'],
            );
        } catch (\Throwable $e) {
            $io->warning('IP enrichment failed: ' . $e->getMessage());
            $enrichment = null;
        }

        $subnet = $this->ipEnrichment->subnet($ip);
        $deviceHash = $this->ipEnrichment->deviceHash($subnet, null);

        $traceArr = [
            'geo_country'  => $enrichment['country'] ?? null,
            'isp_name'     => $enrichment['isp'] ?? null,
            'ip_subnet'    => $subnet,
            'device_hash'  => $deviceHash,
            'created_at'   => time(),
            'success'      => 1,
        ];

        $io->section('Scoring (no baseline — all rules fire at full strength)');
        $result = $this->rules->score($traceArr, null, $enrichment, null, null);

        $io->definitionList(
            ['Total Score' => $result['score'] . '/100'],
            ['Severity'    => strtoupper($result['severity'])],
            ['Decision'    => $result['decision']],
        );

        $io->section('Rule Breakdown');
        $rows = [];
        foreach ($result['rules'] as $rule => $points) {
            $rows[] = [$rule, $points];
        }
        $io->table(['Rule', 'Points'], $rows);

        $threshold = $this->central->suspiciousLoginDetectionEnabled()
            ? $this->central->suspiciousLoginScoreThreshold()
            : 20;

        if ($result['score'] >= $threshold) {
            $io->warning(sprintf(
                'Score %d meets threshold %d — this login would trigger a suspicious event.',
                $result['score'],
                $threshold,
            ));
        } else {
            $io->success(sprintf(
                'Score %d is below threshold %d — this login would NOT trigger an event.',
                $result['score'],
                $threshold,
            ));
        }

        return Command::SUCCESS;
    }
}
