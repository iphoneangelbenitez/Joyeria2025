<?php
// test_css.php
require_once "config/paths.php";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Prueba de CSS</title>
    <link href="<?php echo CSS_PATH; ?>ventas.css" rel="stylesheet">
</head>
<body>
    <h1>Prueba de carga de CSS</h1>
    <p>Si este texto tiene estilos, el CSS se está cargando correctamente.</p>
    <p>Ruta del CSS: <?php echo CSS_PATH; ?>ventas.css</p>
    <p>Ruta absoluta: <?php echo realpath('assets/css/ventas.css'); ?></p>
</body>
</html>