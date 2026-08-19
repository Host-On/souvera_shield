<?php
declare(strict_types=1);

/**
 * Additional OCP stubs on top of stubs.php.
 *
 * Loaded unconditionally by tests/bootstrap.php (PHPUnit) and by the
 * standalone reflection scripts under tests/*_check.php. Every declaration
 * is guarded with `interface_exists` / `class_exists` so that installing
 * the real `nextcloud/ocp` dev dependency continues to work.
 */

namespace OCP {
    if (!interface_exists('OCP\\IRequest')) {
        interface IRequest {
            public function getParam(string $key, $default = null);
        }
    }
    if (!interface_exists('OCP\\IUserSession')) {
        interface IUserSession {
            public function getUser();
        }
    }
}

namespace OCP\AppFramework {
    if (!class_exists('OCP\\AppFramework\\Controller')) {
        abstract class Controller {
            protected \OCP\IRequest $request;
            public function __construct(string $appName, \OCP\IRequest $request) {
                $this->request = $request;
            }
        }
    }
    if (!class_exists('OCP\\AppFramework\\Http')) {
        class Http {
            const STATUS_BAD_GATEWAY         = 502;
            const STATUS_FAILED_DEPENDENCY   = 424;
            const STATUS_PRECONDITION_FAILED = 412;
            const STATUS_NOT_FOUND           = 404;
        }
    }
}

namespace OCP\AppFramework\Http {
    if (!class_exists('OCP\\AppFramework\\Http\\JSONResponse')) {
        class JSONResponse {
            public function __construct(public $data = [], public int $status = 200) {}
        }
    }
}

namespace OCP\AppFramework\Db {
    if (!class_exists('OCP\\AppFramework\\Db\\Entity')) {
        abstract class Entity {
            protected $id;
            public function getId() { return $this->id; }
            public function setId($id) { $this->id = $id; }
            public function addType(string $name, string $type): void {}
            public function __call($method, $args) {
                $type = substr($method, 0, 3);
                $prop = lcfirst(substr($method, 3));
                if ($type === 'get') return $this->$prop ?? null;
                if ($type === 'set') { $this->$prop = $args[0] ?? null; return; }
            }
        }
    }
    if (!class_exists('OCP\\AppFramework\\Db\\QBMapper')) {
        abstract class QBMapper {
            public function insert($entity) { return $entity; }
            public function delete($entity) { return $entity; }
            public function update($entity) { return $entity; }
        }
    }
    if (!class_exists('OCP\\AppFramework\\Db\\DoesNotExistException')) {
        class DoesNotExistException extends \Exception {}
    }
}

namespace OCP\AppFramework\Http\Attribute {
    if (!class_exists('OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired')) {
        #[\Attribute]
        class NoAdminRequired {}
    }
}

namespace OCP\Migration {
    if (!interface_exists('OCP\\Migration\\IOutput')) {
        interface IOutput {}
    }
    if (!class_exists('OCP\\Migration\\SimpleMigrationStep')) {
        abstract class SimpleMigrationStep {}
    }
}

namespace OCP\DB {
    if (!interface_exists('OCP\\DB\\ISchemaWrapper')) {
        interface ISchemaWrapper {}
    }
    if (!class_exists('OCP\\DB\\Types')) {
        class Types {
            const STRING  = 'string';
            const TEXT    = 'text';
            const BIGINT  = 'bigint';
            const INTEGER = 'integer';
        }
    }
}

namespace OCP\Dashboard {
    if (!interface_exists('OCP\\Dashboard\\IWidget')) {
        interface IWidget {
            public function getId(): string;
            public function getTitle(): string;
            public function getOrder(): int;
            public function getIconClass(): string;
            public function getUrl(): ?string;
            public function load(): void;
        }
    }
    if (!interface_exists('OCP\\Dashboard\\IAPIWidget')) {
        interface IAPIWidget extends IWidget {
            public function getItems(string $userId, ?string $since = null, int $limit = 7): array;
        }
    }
    if (!interface_exists('OCP\\Dashboard\\IAPIWidgetV2')) {
        interface IAPIWidgetV2 extends IWidget {
            public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): \OCP\Dashboard\Model\WidgetItems;
        }
    }
    if (!interface_exists('OCP\\Dashboard\\IIconWidget')) {
        interface IIconWidget extends IWidget {
            public function getIconUrl(): string;
        }
    }
}

namespace OCP\Dashboard\Model {
    if (!class_exists('OCP\\Dashboard\\Model\\WidgetItem')) {
        class WidgetItem {
            public function __construct(
                string $title = '',
                string $subtitle = '',
                string $link = '',
                string $iconUrl = '',
                string $sinceId = '0'
            ) {}
        }
    }
    if (!class_exists('OCP\\Dashboard\\Model\\WidgetItems')) {
        class WidgetItems {
            public function __construct(
                public array $items = [],
                public string $emptyContentMessage = '',
                public string $halfEmptyContentMessage = '',
            ) {}
        }
    }
}

namespace OCP\Search {
    if (!interface_exists('OCP\\Search\\IProvider')) {
        interface IProvider {}
    }
    if (!interface_exists('OCP\\Search\\ISearchQuery')) {
        interface ISearchQuery {}
    }
    if (!class_exists('OCP\\Search\\SearchResult')) {
        class SearchResult {
            public static function complete(string $name, array $entries): self { return new self(); }
        }
    }
    if (!class_exists('OCP\\Search\\SearchResultEntry')) {
        class SearchResultEntry {
            public function __construct(
                string $thumbnailUrl,
                string $title,
                string $subline,
                string $resourceUrl,
                string $icon = '',
                bool $rounded = false
            ) {}
        }
    }
}

namespace OCP\L10N {
    if (!interface_exists('OCP\\L10N\\IFactory')) {
        interface IFactory {
            public function get(string $app, ?string $lang = null): \OCP\IL10N;
        }
    }
}

namespace OCP {
    if (!interface_exists('OCP\\IL10N')) {
        interface IL10N {
            public function t(string $text, array $parameters = []): string;
            public function n(string $textSingular, string $textPlural, int $count, array $parameters = []): string;
        }
    }
    if (!interface_exists('OCP\\IURLGenerator')) {
        interface IURLGenerator {
            public function linkToRouteAbsolute(string $routeName, array $arguments = []): string;
            public function imagePath(string $appName, string $file): string;
            public function getAbsoluteURL(string $url): string;
        }
    }
    if (!interface_exists('OCP\\IUser')) {
        interface IUser {
            public function getUID(): string;
            public function getEMailAddress(): ?string;
        }
    }
    if (!interface_exists('OCP\\IUserSession')) {
        interface IUserSession {
            public function getUser(): ?\OCP\IUser;
        }
    }
    if (!interface_exists('OCP\\IAppConfig')) {
        interface IAppConfig {}
    }
    if (!interface_exists('OCP\\IConfig')) {
        interface IConfig {}
    }
    if (!interface_exists('OCP\\Http\\Client\\IClientService')) {
        // fall-through, defined below
    }
}

namespace OCP\Http\Client {
    if (!interface_exists('OCP\\Http\\Client\\IClientService')) {
        interface IClientService {}
    }
}

namespace OCP\Security {
    if (!interface_exists('OCP\\Security\\ICrypto')) {
        interface ICrypto {}
    }
}

namespace Psr\Log {
    if (!interface_exists('Psr\\Log\\LoggerInterface')) {
        interface LoggerInterface {}
    }
}

namespace OCP {
    if (!interface_exists('OCP\\IUserManager')) {
        interface IUserManager {
            public function callForSeenUsers(\Closure $callback): void;
        }
    }
}

namespace OCP\Notification {
    if (!interface_exists('OCP\\Notification\\INotification')) {
        interface INotification {
            public function setApp(string $app): self;
            public function setUser(string $user): self;
            public function setDateTime(\DateTime $dt): self;
            public function setObject(string $type, string $id): self;
            public function setSubject(string $subject, array $params = []): self;
            public function getApp(): string;
            public function getSubject(): string;
            public function getSubjectParameters(): array;
            public function setRichSubject(string $subject, array $params = []): self;
            public function setParsedSubject(string $subject): self;
            public function setLink(string $link): self;
            public function setIcon(string $icon): self;
        }
    }
    if (!interface_exists('OCP\\Notification\\INotifier')) {
        interface INotifier {}
    }
    if (!interface_exists('OCP\\Notification\\IManager')) {
        interface IManager {
            public function createNotification(): \OCP\Notification\INotification;
            public function notify(\OCP\Notification\INotification $notification): void;
        }
    }
}

namespace OCP\AppFramework\Utility {
    if (!interface_exists('OCP\\AppFramework\\Utility\\ITimeFactory')) {
        interface ITimeFactory {
            public function getTime(): int;
            public function getDateTime(string $time = 'now', ?\DateTimeZone $timezone = null): \DateTime;
        }
    }
}

namespace OCP\BackgroundJob {
    if (!class_exists('OCP\\BackgroundJob\\Job')) {
        abstract class Job {
            public const TIME_INSENSITIVE = 1;
            protected \OCP\AppFramework\Utility\ITimeFactory $time;
            public function __construct(\OCP\AppFramework\Utility\ITimeFactory $time) {
                $this->time = $time;
            }
        }
    }
    if (!class_exists('OCP\\BackgroundJob\\TimedJob')) {
        abstract class TimedJob extends Job {
            protected function setInterval(int $seconds): void {}
            protected function setTimeSensitivity(int $sensitivity): void {}
            abstract protected function run($argument): void;
        }
    }
    if (!interface_exists('OCP\\BackgroundJob\\IJobList')) {
        interface IJobList {
            public function add(string $job, $argument = null): void;
            public function has(string $job, $argument = null): bool;
        }
    }
}

namespace OCP\SetupCheck {
    if (!class_exists('OCP\\SetupCheck\\SetupResult')) {
        class SetupResult {
            private function __construct(
                private string $severity,
                private ?string $description,
            ) {}
            public function getSeverity(): string { return $this->severity; }
            public function getDescription(): ?string { return $this->description; }
            public static function success(?string $description = null): self { return new self('success', $description); }
            public static function info(?string $description = null): self { return new self('info', $description); }
            public static function warning(?string $description = null): self { return new self('warning', $description); }
            public static function error(?string $description = null): self { return new self('error', $description); }
        }
    }
    if (!interface_exists('OCP\\SetupCheck\\ISetupCheck')) {
        interface ISetupCheck {
            public function getCategory(): string;
            public function getName(): string;
            public function run(): SetupResult;
        }
    }
}


