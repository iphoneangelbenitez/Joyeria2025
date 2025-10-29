<?php
// config/paths.php

// Determinar el protocolo HTTP o HTTPS
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";

// Obtener el nombre del host
$host = $_SERVER['HTTP_HOST'];

// Obtener la ruta del script actual
$script_path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));

// Definir la URL base
define('BASE_URL', $protocol . '://' . $host . $script_path . '/');

// Definir la ruta base del sistema de archivos
define('BASE_PATH', realpath(dirname(__FILE__) . '/../') . '/');

// Definir rutas de assets
define('CSS_PATH', BASE_URL . 'assets/css/');
define('JS_PATH', BASE_URL . 'assets/js/');
define('IMG_PATH', BASE_URL . 'assets/img/');
?>