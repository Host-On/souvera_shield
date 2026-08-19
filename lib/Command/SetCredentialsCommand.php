<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Command;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Service\PMGClient;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Set / rotate the PMG credentials stored in the app config.
 *
 * Values are written through the typed lazy IAppConfig API (NC 30+):
 *   - String values use {@see IAppConfig::setValueString} with lazy=true so
 *     they never trigger a "lazy AppConfig loaded eagerly" debug log.
 *   - The password gets sensitive=true on top, so it is masked in occ output
 *     and in the admin UI.
 *   - The password is additionally encrypted with the server-wide
 *     {@see ICrypto} secret before it is stored.
 *
 *   occ souvera_shield:set-credentials \
 *       --domain="https://pmg.example.com:8006" \
 *       --username="shield@pmg" \
 *       --password="…" \
 *       --allowed-domains="example.com,souvera.eu"
 */
class SetCredentialsCommand extends Command {

    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly ICrypto $crypto,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('souvera_shield:set-credentials')
            ->setDescription('Set the Proxmox Mail Gateway credentials (password is encrypted on disk).')
            ->addOption('domain',   'd', InputOption::VALUE_REQUIRED, 'PMG base URL, e.g. https://pmg.example.com:8006')
            ->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'PMG technical user, e.g. shield@pmg')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'PMG password (will be encrypted)')
            ->addOption('insecure', null, InputOption::VALUE_REQUIRED, 'Skip TLS verification: true|false')
            ->addOption('allowed-domains', null, InputOption::VALUE_REQUIRED, 'Comma separated list of e-mail domains the app may manage.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $map = [
            'domain'          => 'pmg_domain',
            'username'        => 'pmg_username',
            'allowed-domains' => 'pmg_allowed_domains',
        ];

        foreach ($map as $opt => $key) {
            $val = $input->getOption($opt);
            if ($val === null || $val === '') {
                continue;
            }
            $this->appConfig->setValueString(Application::APP_ID, $key, (string)$val, lazy: true);
            $output->writeln(sprintf('<info>%s = %s</info>', $key, $val));
        }

        $insecure = $input->getOption('insecure');
        if ($insecure !== null && $insecure !== '') {
            $bool = filter_var($insecure, FILTER_VALIDATE_BOOLEAN);
            $this->appConfig->setValueBool(Application::APP_ID, 'pmg_allow_insecure', $bool, lazy: true);
            $output->writeln(sprintf('<info>pmg_allow_insecure = %s</info>', $bool ? 'true' : 'false'));
        }

        $password = $input->getOption('password');
        if ($password !== null && $password !== '') {
            $cipher = PMGClient::ENCRYPTION_PREFIX . $this->crypto->encrypt($password);
            $this->appConfig->setValueString(
                Application::APP_ID,
                'pmg_password',
                $cipher,
                lazy: true,
                sensitive: true,
            );
            $output->writeln('<info>Password encrypted and stored (sensitive).</info>');
        }

        return Command::SUCCESS;
    }
}
