<?php
declare(strict_types=1);

/**
 * Souvera Shield – OCC command registration.
 *
 * Returns the list of Symfony commands the app exposes through Nextcloud's
 * `occ` binary. The command itself is built through the dependency injection
 * container so all collaborators (IConfig, ICrypto, …) are wired
 * automatically.
 */

/** @var \Psr\Container\ContainerInterface $container */
return [
    \OCP\Server::get(\OCA\SouveraShield\Command\SetCredentialsCommand::class),
];
