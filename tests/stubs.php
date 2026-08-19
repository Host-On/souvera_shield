<?php
declare(strict_types=1);

/**
 * Minimal stubs for the OCP interfaces touched by Souvera Shield.
 *
 * Only used when running PHPUnit without installing the official
 * nextcloud/ocp dev dependency. They expose just the surface the
 * code under test depends on, so test doubles can implement them.
 */

namespace OCP {
    if (!interface_exists('OCP\\IConfig')) {
        interface IConfig {
            public function getSystemValue(string $key, $default = '');
            public function getAppValue(string $appName, string $key, string $default = '');
            public function setAppValue(string $appName, string $key, string $value): void;
        }
    }
    if (!interface_exists('OCP\\IAppConfig')) {
        interface IAppConfig {
            public function getValueString(string $app, string $key, string $default = '', bool $lazy = false): string;
            public function getValueBool(string $app, string $key, bool $default = false, bool $lazy = false): bool;
            public function getValueInt(string $app, string $key, int $default = 0, bool $lazy = false): int;
            public function setValueString(string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool;
            public function setValueBool(string $app, string $key, bool $value, bool $lazy = false): bool;
            public function setValueInt(string $app, string $key, int $value, bool $lazy = false, bool $sensitive = false): bool;
        }
    }
}

namespace OCP\App {
    if (!interface_exists('OCP\\App\\IAppManager')) {
        interface IAppManager {
            public function isInstalled(string $appId): bool;
            public function isEnabledForUser(string $appId, $user = null): bool;
            public function getAppVersion(string $appId, bool $useCache = true): string;
        }
    }
}

namespace OCP\AppFramework {
    if (!class_exists('OCP\\AppFramework\\App')) {
        class App {
            public function __construct(string $appId, array $urlParams = []) {}
        }
    }
}

namespace OCP\AppFramework\Db {
    if (!class_exists('OCP\\AppFramework\\Db\\QBMapper')) {
        // Minimal QBMapper surface: enough for createMock() on subclasses.
        class QBMapper {
            protected $tableName = '';
            public function __construct($db = null, string $tableName = '') {
                $this->tableName = $tableName;
            }
            public function insert($entity) { return $entity; }
            public function update($entity) { return $entity; }
            public function delete($entity) { return $entity; }
        }
    }
    if (!class_exists('OCP\\AppFramework\\Db\\Entity')) {
        class Entity {
            protected $id;
            public function getId() { return $this->id; }
            public function setId($id) { $this->id = $id; }
            protected function addType(string $field, string $type): void {}
            public function __call($method, $args) {
                $kind = substr($method, 0, 3);
                $prop = lcfirst(substr($method, 3));
                if ($kind === 'get') { return $this->$prop ?? null; }
                if ($kind === 'set') { $this->$prop = $args[0] ?? null; return; }
                return null;
            }
        }
    }
    if (!class_exists('OCP\\AppFramework\\Db\\DoesNotExistException')) {
        class DoesNotExistException extends \Exception {}
    }
}

namespace OCP\Mail {
    if (!interface_exists('OCP\\Mail\\IMessage')) {
        interface IMessage {
            public function setFrom(array $addresses): self;
            public function setTo(array $addresses): self;
            public function setSubject(string $subject): self;
            public function setPlainBody(string $body): self;
        }
    }
    if (!interface_exists('OCP\\Mail\\IMailer')) {
        interface IMailer {
            public function createMessage(): IMessage;
            public function send(IMessage $message): array;
        }
    }
}

namespace OCP\AppFramework\Bootstrap {
    if (!interface_exists('OCP\\AppFramework\\Bootstrap\\IBootContext')) {
        interface IBootContext {}
    }
    if (!interface_exists('OCP\\AppFramework\\Bootstrap\\IBootstrap')) {
        interface IBootstrap {}
    }
    if (!interface_exists('OCP\\AppFramework\\Bootstrap\\IRegistrationContext')) {
        interface IRegistrationContext {}
    }
}

namespace OCP\Http\Client {
    if (!interface_exists('OCP\\Http\\Client\\IResponse')) {
        interface IResponse {
            public function getStatusCode(): int;
            public function getBody();
        }
    }
    if (!interface_exists('OCP\\Http\\Client\\IClient')) {
        interface IClient {
            public function get(string $uri, array $options = []);
            public function post(string $uri, array $options = []);
            public function delete(string $uri, array $options = []);
        }
    }
    if (!interface_exists('OCP\\Http\\Client\\IClientService')) {
        interface IClientService {
            public function newClient(): IClient;
        }
    }
}

namespace OCP\Security {
    if (!interface_exists('OCP\\Security\\ICrypto')) {
        interface ICrypto {
            public function encrypt(string $plaintext, string $password = ''): string;
            public function decrypt(string $authenticatedCiphertext, string $password = ''): string;
        }
    }
}

namespace Psr\Log {
    if (!interface_exists('Psr\\Log\\LoggerInterface')) {
        interface LoggerInterface {
            public function error(string|\Stringable $message, array $context = []): void;
            public function warning(string|\Stringable $message, array $context = []): void;
            public function info(string|\Stringable $message, array $context = []): void;
            public function debug(string|\Stringable $message, array $context = []): void;
        }
    }
}
