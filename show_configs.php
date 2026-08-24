<?php

use App\Tools\Config\Config;

/** Require library and config bootstrap */
require_once __DIR__ . "/bootstrap.php";
Config::init();
/** Get application configs items array */
$staticProps = (new ReflectionClass(Config::class))
    ->getProperties(ReflectionProperty::IS_STATIC | ReflectionProperty::IS_PUBLIC);
foreach ($staticProps as $prop) {
    $value = $prop->getValue();
    if (is_array($value)) {
        $value = json_encode($value);
    }
    if ($prop->getType()->getName() === 'bool'){
        $value = $value === true ? 'true' : 'false';
    }
    echo "🟢 {$prop->getName()} => $value  [type:{$prop->getType()->getName()}]" . PHP_EOL; ;
}