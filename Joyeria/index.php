<?php
session_start();

// Compatibilidad de nombres de sesión en español e inglés
if (!isset($_SESSION['id_usuario']) && isset($_SESSION['user_id'])) {
    $_SESSION['id_usuario'] = $_SESSION['user_id'];
}
if (!isset($_SESSION['tipo_usuario']) && isset($_SESSION['user_type'])) {
    $_SESSION['tipo_usuario'] = $_SESSION['user_type'];
}

// Verificación de sesión (usa las nuevas claves; cae a las viejas si existen)
if (!isset($_SESSION['id_usuario']) && !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once "config/database.php";
$baseDeDatos = new Database();
$conexion = $baseDeDatos->getConnection();

// === Obtener estadísticas para el tablero ===

// Ventas de hoy
$consultaVentasHoy = "SELECT COUNT(*) AS total FROM ventas WHERE DATE(fecha) = CURDATE()";
$sentenciaVentasHoy = $conexion->prepare($consultaVentasHoy);
$sentenciaVentasHoy->execute();
$ventasHoy = $sentenciaVentasHoy->fetch(PDO::FETCH_ASSOC)['total'];

// Productos con stock bajo (no ocultos)
$consultaProductosBajoStock = "SELECT COUNT(*) AS total FROM productos WHERE stock <= stock_minimo AND oculto = 0";
$sentenciaProductos = $conexion->prepare($consultaProductosBajoStock);
$sentenciaProductos->execute();
$productosBajoStock = $sentenciaProductos->fetch(PDO::FETCH_ASSOC)['total'];

// Servicios pendientes
$consultaServiciosPendientes = "SELECT COUNT(*) AS total FROM servicios WHERE estado = 'PENDIENTE'";
$sentenciaServicios = $conexion->prepare($consultaServiciosPendientes);
$sentenciaServicios->execute();
$serviciosPendientes = $sentenciaServicios->fetch(PDO::FETCH_ASSOC)['total'];

// === Listados recientes ===

// Ventas recientes (últimas 5)
$consultaVentasRecientes = "
    SELECT v.id, v.fecha, c.nombre AS cliente_nombre, c.apellido AS cliente_apellido, v.total 
    FROM ventas v 
    INNER JOIN clientes c ON v.id_cliente = c.id 
    ORDER BY v.fecha DESC 
    LIMIT 5
";
$sentenciaVentasRecientes = $conexion->prepare($consultaVentasRecientes);
$sentenciaVentasRecientes->execute();
$ventasRecientes = $sentenciaVentasRecientes->fetchAll(PDO::FETCH_ASSOC);

// Servicios recientes (últimos 5)
$consultaServiciosRecientes = "
    SELECT s.id, s.fecha_ingreso, c.nombre AS cliente_nombre, c.apellido AS cliente_apellido, 
           s.producto, s.estado 
    FROM servicios s 
    INNER JOIN clientes c ON s.id_cliente = c.id 
    ORDER BY s.fecha_ingreso DESC 
    LIMIT 5
";
$sentenciaServiciosRecientes = $conexion->prepare($consultaServiciosRecientes);
$sentenciaServiciosRecientes->execute();
$serviciosRecientes = $sentenciaServiciosRecientes->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Sistema de Joyería</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/theme-oscuro.css">

    // boton claro oscuro
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'light') {
                // Aplica la clase al <html>
                document.documentElement.classList.add('theme-light');
            }
        })();
    </script>

    <style>
        #theme-toggle-btn .icon-moon { display: none; }
        #theme-toggle-btn .icon-sun { display: inline-block; }
        html.theme-light #theme-toggle-btn .icon-moon { display: inline-block; }
        html.theme-light #theme-toggle-btn .icon-sun { display: none; }
    </style>
    


</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-md-12 mb-4">
                <div class="dashboard-header">
                    <h2><i class="bi bi-gem me-2"></i>Dashboard - Joyería Sosa</h2>
                    <p class="mb-0">Resumen general del sistema</p>
                </div>
            </div>
        </div>

        <!-- Tarjetas de estadísticas -->
        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="stat-card stat-card-primary">
                    <div class="stat-card-icon"><i class="bi bi-cash-coin"></i></div>
                    <div class="stat-card-value"><?php echo $ventasHoy; ?></div>
                    <div class="stat-card-title">Ventas Hoy</div>
                    <a href="ventas.php" class="btn btn-outline-primary btn-sm">Ver detalles</a>
                </div>
            </div>

            <div class="col-md-4 mb-4">
                <div class="stat-card stat-card-warning">
                    <div class="stat-card-icon"><i class="bi bi-exclamation-triangle"></i></div>
                    <div class="stat-card-value"><?php echo $productosBajoStock; ?></div>
                    <div class="stat-card-title">Productos con Stock Bajo</div>
                    <a href="inventario.php" class="btn btn-outline-warning btn-sm">Ver detalles</a>
                </div>
            </div>

            <div class="col-md-4 mb-4">
                <div class="stat-card stat-card-info">
                    <div class="stat-card-icon"><i class="bi bi-tools"></i></div>
                    <div class="stat-card-value"><?php echo $serviciosPendientes; ?></div>
                    <div class="stat-card-title">Servicios Pendientes</div>
                    <a href="servicios.php" class="btn btn-outline-info btn-sm">Ver detalles</a>
                </div>
            </div>
        </div>

        <!-- Acciones rápidas -->
        <div class="row">
            <div class="col-md-12">
                <div class="quick-actions">
                    <h4 class="mb-4">Acciones Rápidas</h4>
                    <div class="row">
                        <div class="col-md-3 col-sm-6 mb-3">
                            <a href="ventas.php?action=nueva" class="action-btn">
                                <i class="bi bi-cash-coin"></i>
                                <span>Nueva Venta</span>
                            </a>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <a href="inventario.php?action=nuevo" class="action-btn">
                                <i class="bi bi-boxes"></i>
                                <span>Nuevo Producto</span>
                            </a>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <a href="servicios.php?action=nuevo" class="action-btn">
                                <i class="bi bi-tools"></i>
                                <span>Nuevo Servicio</span>
                            </a>
                        </div>
                        <?php 
                        // Mostrar "Nuevo Cliente" sólo a administradores (ADM). Compatibilidad con claves en inglés.
                        $esAdmin = false;
                        if (isset($_SESSION['tipo_usuario']) && $_SESSION['tipo_usuario'] === 'ADM') $esAdmin = true;
                        if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'ADM') $esAdmin = true;

                        if ($esAdmin): ?>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <a href="clientes.php?action=nuevo" class="action-btn">
                                <i class="bi bi-people"></i>
                                <span>Nuevo Cliente</span>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actividad reciente -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="recent-card">
                    <div class="recent-card-header">
                        <h5 class="mb-0">Ventas Recientes</h5>
                    </div>
                    <div class="recent-card-body">
                        <?php if (count($ventasRecientes) > 0): ?>
                            <?php foreach ($ventasRecientes as $venta): ?>
                                <div class="recent-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-0">Venta #<?php echo (int)$venta['id']; ?></h6>
                                            <small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($venta['fecha'])); ?></small>
                                        </div>
                                        <div class="text-end">
                                            <div>
                                                <?php 
                                                // Verificar si es un array antes de imprimir (defensivo)
                                                if (is_array($venta['cliente_nombre'])) {
                                                    echo htmlspecialchars(implode(' ', $venta['cliente_nombre']));
                                                } else {
                                                    echo htmlspecialchars($venta['cliente_nombre'] . ' ' . $venta['cliente_apellido']);
                                                }
                                                ?>
                                            </div>
                                            <strong>$<?php echo number_format((float)$venta['total'], 2); ?></strong>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-3">
                                <i class="bi bi-receipt display-4"></i>
                                <p>No hay ventas recientes</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="recent-card">
                    <div class="recent-card-header">
                        <h5 class="mb-0">Servicios Recientes</h5>
                    </div>
                    <div class="recent-card-body">
                        <?php if (count($serviciosRecientes) > 0): ?>
                            <?php foreach ($serviciosRecientes as $servicio): ?>
                                <div class="recent-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-0"><?php echo htmlspecialchars($servicio['producto']); ?></h6>
                                            <small class="text-muted"><?php echo date('d/m/Y', strtotime($servicio['fecha_ingreso'])); ?></small>
                                        </div>
                                        <div class="text-end">
                                            <div>
                                                <?php 
                                                if (is_array($servicio['cliente_nombre'])) {
                                                    echo htmlspecialchars(implode(' ', $servicio['cliente_nombre']));
                                                } else {
                                                    echo htmlspecialchars($servicio['cliente_nombre'] . ' ' . $servicio['cliente_apellido']);
                                                }
                                                ?>
                                            </div>
                                            <?php 
                                            // Mapeo de estado a clase y texto legible
                                            $estado = $servicio['estado'];
                                            $claseEstado = 'pendiente';
                                            $textoEstado = 'Pendiente';
                                            switch ($estado) {
                                                case 'PENDIENTE':  $claseEstado = 'pendiente';  $textoEstado = 'Pendiente';  break;
                                                case 'EN_PROCESO': $claseEstado = 'proceso';    $textoEstado = 'En Proceso'; break;
                                                case 'COMPLETADO': $claseEstado = 'completado'; $textoEstado = 'Completado'; break;
                                            }
                                            ?>
                                            <span class="badge-estado badge-<?php echo $claseEstado; ?>">
                                                <?php echo $textoEstado; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-3">
                                <i class="bi bi-tools display-4"></i>
                                <p>No hay servicios recientes</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script src="assets/js/boton-oscuro.js"></script>


</body>
</html>
