<?php
// reportes_ventas.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Verificar permisos de usuario
$esAdministrador = ($_SESSION['user_type'] == 'ADM');

require_once "config/database.php";
$database = new Database();
$db = $database->getConnection();

// Obtener parámetros de filtro
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01'); // Primer día del mes actual
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t'); // Último día del mes actual
$metodo_pago = $_GET['metodo_pago'] ?? '';
$id_vendedor = $_GET['id_vendedor'] ?? '';

// Construir consulta base
$query_ventas = "SELECT v.*, c.nombre as cliente_nombre, c.apellido as cliente_apellido, 
                        u.nombre as vendedor_nombre, COUNT(vd.id) as items,
                        SUM(vd.cantidad * vd.precio_unitario) as subtotal_base
                 FROM ventas v
                 INNER JOIN clientes c ON v.id_cliente = c.id
                 INNER JOIN usuarios u ON v.id_usuario = u.id
                 INNER JOIN venta_detalles vd ON v.id = vd.id_venta
                 WHERE v.fecha BETWEEN :fecha_inicio AND :fecha_fin";

$params = [
    ':fecha_inicio' => $fecha_inicio . ' 00:00:00',
    ':fecha_fin' => $fecha_fin . ' 23:59:59'
];

// Aplicar filtros adicionales
if (!empty($metodo_pago)) {
    $query_ventas .= " AND v.metodo_pago = :metodo_pago";
    $params[':metodo_pago'] = $metodo_pago;
}

if (!empty($id_vendedor) && $esAdministrador) {
    $query_ventas .= " AND v.id_usuario = :id_vendedor";
    $params[':id_vendedor'] = $id_vendedor;
}

$query_ventas .= " GROUP BY v.id ORDER BY v.fecha DESC";

// Preparar y ejecutar consulta
$stmt_ventas = $db->prepare($query_ventas);
foreach ($params as $key => $value) {
    $stmt_ventas->bindValue($key, $value);
}
$stmt_ventas->execute();
$ventas = $stmt_ventas->fetchAll(PDO::FETCH_ASSOC);

// Obtener estadísticas generales
$query_estadisticas = "SELECT 
    COUNT(*) as total_ventas,
    SUM(v.total) as ingresos_totales,
    AVG(v.total) as promedio_venta,
    SUM(v.descuento) as descuentos_totales,
    SUM(vd.cantidad) as productos_vendidos
FROM ventas v
INNER JOIN venta_detalles vd ON v.id = vd.id_venta
WHERE v.fecha BETWEEN :fecha_inicio AND :fecha_fin";

$params_estadisticas = [
    ':fecha_inicio' => $fecha_inicio . ' 00:00:00',
    ':fecha_fin' => $fecha_fin . ' 23:59:59'
];

if (!empty($metodo_pago)) {
    $query_estadisticas .= " AND v.metodo_pago = :metodo_pago";
    $params_estadisticas[':metodo_pago'] = $metodo_pago;
}

$stmt_estadisticas = $db->prepare($query_estadisticas);
foreach ($params_estadisticas as $key => $value) {
    $stmt_estadisticas->bindValue($key, $value);
}
$stmt_estadisticas->execute();
$estadisticas = $stmt_estadisticas->fetch(PDO::FETCH_ASSOC);

// Obtener ventas por método de pago
$query_metodos_pago = "SELECT 
    metodo_pago,
    COUNT(*) as cantidad,
    SUM(total) as monto_total
FROM ventas 
WHERE fecha BETWEEN :fecha_inicio AND :fecha_fin
GROUP BY metodo_pago";

$stmt_metodos = $db->prepare($query_metodos_pago);
$stmt_metodos->bindValue(':fecha_inicio', $fecha_inicio . ' 00:00:00');
$stmt_metodos->bindValue(':fecha_fin', $fecha_fin . ' 23:59:59');
$stmt_metodos->execute();
$metodos_pago = $stmt_metodos->fetchAll(PDO::FETCH_ASSOC);

// Obtener ventas por día (para gráfico)
$query_ventas_por_dia = "SELECT 
    DATE(fecha) as dia,
    COUNT(*) as cantidad_ventas,
    SUM(total) as monto_total
FROM ventas 
WHERE fecha BETWEEN :fecha_inicio AND :fecha_fin
GROUP BY DATE(fecha)
ORDER BY dia";

$stmt_dias = $db->prepare($query_ventas_por_dia);
$stmt_dias->bindValue(':fecha_inicio', $fecha_inicio . ' 00:00:00');
$stmt_dias->bindValue(':fecha_fin', $fecha_fin . ' 23:59:59');
$stmt_dias->execute();
$ventas_por_dia = $stmt_dias->fetchAll(PDO::FETCH_ASSOC);

// Obtener vendedores (solo para administradores)
$vendedores = [];
if ($esAdministrador) {
    $query_vendedores = "SELECT id, nombre FROM usuarios WHERE tipo = 'USER' ORDER BY nombre";
    $stmt_vendedores = $db->prepare($query_vendedores);
    $stmt_vendedores->execute();
    $vendedores = $stmt_vendedores->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes de Ventas - Sistema de Joyería</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="assets/css/reportes_ventas.css" />
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="reportes-container">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-12">
                    <div class="reportes-header">
                        <h2><i class="bi bi-graph-up me-2"></i>Reportes de Ventas</h2>
                        <p class="mb-0">Análisis y estadísticas de ventas</p>
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="filtros-container">
                        <h5>Filtros de Reporte</h5>
                        <form method="get" action="reportes_ventas.php" class="row g-3">
                            <div class="col-md-3">
                                <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                                <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="fecha_fin" class="form-label">Fecha Fin</label>
                                <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" value="<?php echo $fecha_fin; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="metodo_pago" class="form-label">Método de Pago</label>
                                <select class="form-select" id="metodo_pago" name="metodo_pago">
                                    <option value="">Todos los métodos</option>
                                    <option value="EFECTIVO" <?php echo $metodo_pago == 'EFECTIVO' ? 'selected' : ''; ?>>Efectivo</option>
                                    <option value="TARJETA" <?php echo $metodo_pago == 'TARJETA' ? 'selected' : ''; ?>>Tarjeta</option>
                                    <option value="TRANSFERENCIA" <?php echo $metodo_pago == 'TRANSFERENCIA' ? 'selected' : ''; ?>>Transferencia</option>
                                </select>
                            </div>
                            <?php if ($esAdministrador): ?>
                            <div class="col-md-3">
                                <label for="id_vendedor" class="form-label">Vendedor</label>
                                <select class="form-select" id="id_vendedor" name="id_vendedor">
                                    <option value="">Todos los vendedores</option>
                                    <?php foreach ($vendedores as $vendedor): ?>
                                        <option value="<?php echo $vendedor['id']; ?>" <?php echo $id_vendedor == $vendedor['id'] ? 'selected' : ''; ?>>
                                            <?php echo $vendedor['nombre']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="col-md-12">
                                <button type="submit" class="btn btn-primary">Aplicar Filtros</button>
                                <a href="reportes_ventas.php" class="btn btn-secondary">Restablecer</a>
                                <button type="button" class="btn btn-success" onclick="exportarPDF()">
                                    <i class="bi bi-file-earmark-pdf"></i> Exportar PDF
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Estadísticas generales -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Total de Ventas</div>
                        <div class="estadistica-valor"><?php echo $estadisticas['total_ventas'] ?? 0; ?></div>
                        <div class="estadistica-descripcion">Período seleccionado</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Ingresos Totales</div>
                        <div class="estadistica-valor">$<?php echo number_format($estadisticas['ingresos_totales'] ?? 0, 2); ?></div>
                        <div class="estadistica-descripcion">Período seleccionado</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Promedio por Venta</div>
                        <div class="estadistica-valor">$<?php echo number_format($estadisticas['promedio_venta'] ?? 0, 2); ?></div>
                        <div class="estadistica-descripcion">Período seleccionado</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Productos Vendidos</div>
                        <div class="estadistica-valor"><?php echo $estadisticas['productos_vendidos'] ?? 0; ?></div>
                        <div class="estadistica-descripcion">Período seleccionado</div>
                    </div>
                </div>
            </div>

            <!-- Gráficos -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="reportes-card">
                        <div class="reportes-card-header">
                            <h5 class="mb-0">Ventas por Día</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="chartVentasPorDia"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="reportes-card">
                        <div class="reportes-card-header">
                            <h5 class="mb-0">Ventas por Método de Pago</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="chartMetodosPago"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabla de ventas -->
            <div class="row">
                <div class="col-md-12">
                    <div class="reportes-card">
                        <div class="reportes-card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Detalle de Ventas</h5>
                            <span class="badge bg-primary">Total: <?php echo count($ventas); ?></span>
                        </div>
                        <div class="card-body">
                            <?php if (count($ventas) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-ventas">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Fecha</th>
                                                <th>Cliente</th>
                                                <th>Vendedor</th>
                                                <th>Método Pago</th>
                                                <th>Items</th>
                                                <th>Descuento</th>
                                                <th>Total</th>
                                                <th>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($ventas as $venta): ?>
                                                <tr>
                                                    <td>#<?php echo $venta['id']; ?></td>
                                                    <td><?php echo date('d/m/Y H:i', strtotime($venta['fecha'])); ?></td>
                                                    <td><?php echo htmlspecialchars($venta['cliente_apellido'] . ', ' . $venta['cliente_nombre']); ?></td>
                                                    <td><?php echo htmlspecialchars($venta['vendedor_nombre']); ?></td>
                                                    <td>
                                                        <span class="badge badge-venta badge-<?php echo strtolower($venta['metodo_pago']); ?>">
                                                            <?php echo $venta['metodo_pago']; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo $venta['items']; ?></td>
                                                    <td><?php echo $venta['descuento']; ?>%</td>
                                                    <td><strong>$<?php echo number_format($venta['total'], 2); ?></strong></td>
                                                    <td>
                                                        <a href="generar_pdf.php?id_venta=<?php echo $venta['id']; ?>" class="btn btn-sm btn-outline-primary" title="Ver Factura">
                                                            <i class="bi bi-receipt"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">No hay ventas que coincidan con los filtros aplicados.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Datos para gráfico de ventas por día
        const ventasPorDiaData = {
            labels: [<?php echo implode(',', array_map(function($v) { return "'" . date('d/m', strtotime($v['dia'])) . "'"; }, $ventas_por_dia)); ?>],
            datasets: [{
                label: 'Monto de Ventas por Día',
                data: [<?php echo implode(',', array_column($ventas_por_dia, 'monto_total')); ?>],
                backgroundColor: 'rgba(212, 175, 55, 0.2)',
                borderColor: 'rgba(212, 175, 55, 1)',
                borderWidth: 2,
                tension: 0.3
            }]
        };

        // Configuración del gráfico de ventas por día
        const ventasPorDiaConfig = {
            type: 'line',
            data: ventasPorDiaData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Monto: $' + context.raw.toFixed(2);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toFixed(2);
                            }
                        }
                    }
                }
            }
        };

        // Datos para gráfico de métodos de pago
        const metodosPagoData = {
            labels: [<?php echo implode(',', array_map(function($v) { return "'" . $v['metodo_pago'] . "'"; }, $metodos_pago)); ?>],
            datasets: [{
                data: [<?php echo implode(',', array_column($metodos_pago, 'monto_total')); ?>],
                backgroundColor: [
                    'rgba(40, 167, 69, 0.8)',    // Efectivo - verde
                    'rgba(0, 123, 255, 0.8)',    // Tarjeta - azul
                    'rgba(108, 117, 125, 0.8)'   // Transferencia - gris
                ],
                borderColor: [
                    'rgba(40, 167, 69, 1)',
                    'rgba(0, 123, 255, 1)',
                    'rgba(108, 117, 125, 1)'
                ],
                borderWidth: 1
            }]
        };

        // Configuración del gráfico de métodos de pago
        const metodosPagoConfig = {
            type: 'doughnut',
            data: metodosPagoData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = context.raw;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                return context.label + ': $' + value.toFixed(2) + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        };

        // Inicializar gráficos cuando el documento esté listo
        document.addEventListener('DOMContentLoaded', function() {
            // Gráfico de ventas por día
            const ctxVentasPorDia = document.getElementById('chartVentasPorDia').getContext('2d');
            new Chart(ctxVentasPorDia, ventasPorDiaConfig);

            // Gráfico de métodos de pago
            const ctxMetodosPago = document.getElementById('chartMetodosPago').getContext('2d');
            new Chart(ctxMetodosPago, metodosPagoConfig);
        });

        // Función para exportar a PDF
        function exportarPDF() {
            // Construir la URL con todos los filtros
            const url = `generar_reporte_pdf.php?fecha_inicio=<?php echo $fecha_inicio; ?>&fecha_fin=<?php echo $fecha_fin; ?>&metodo_pago=<?php echo $metodo_pago; ?>&id_vendedor=<?php echo $id_vendedor; ?>`;
            
            // Abrir la página de impresión en una nueva pestaña
            window.open(url, '_blank');
        }
    </script>
</body>
</html>