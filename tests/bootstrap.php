<?php

$autoloadPath = getenv('CRAFT_HZT_VENDOR_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$loader = require $autoloadPath;
$loader->addPsr4('robuust\\hzt\\', dirname(__DIR__).'/src/');

require_once dirname($loader->findFile(yii\base\Component::class), 2).'/Yii.php';
require_once dirname($loader->findFile(craft\base\Plugin::class), 2).'/Craft.php';
