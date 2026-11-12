<?php
// Local source inspection; no application bootstrap, database, or network.
$root = dirname(__DIR__, 2).'/lang';
$result = [];
foreach (glob($root.'/*/*.php') ?: [] as $file) {
    $result[basename(dirname($file))] = require $file;
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
