<?php
declare(strict_types=1);
// Shared by Apache and CLI. Secrets remain outside DocumentRoot. Explicit
// process environment variables override this private file for local testing.
if (!defined('MEDIVEND_ENV_LOADED')) {
    define('MEDIVEND_ENV_LOADED', true);
    $privateFile = getenv('MEDIVEND_ENV_FILE') ?: '/etc/medivend/medivend.env';
    if (is_file($privateFile)) {
        if (!is_readable($privateFile)) throw new RuntimeException('Private environment is unreadable.');
        $values = parse_ini_file($privateFile, false, INI_SCANNER_RAW);
        if ($values === false) throw new RuntimeException('Private environment is invalid.');
        foreach ($values as $name => $value) if (strpos($name, 'MEDIVEND_') === 0 && getenv($name) === false) putenv($name . '=' . $value);
    } elseif (getenv('MEDIVEND_ENV_FILE')) throw new RuntimeException('Private environment file is missing.');
}
