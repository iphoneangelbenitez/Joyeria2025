<?php
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
$tipo_movimiento = $_GET['tipo_movimiento'] ?? '';
$categoria = $_GET['categoria'] ?? '';
$stock_bajo = isset($_GET['stock_bajo']) ? true : false;

// Construir consulta para movimientos de stock
$query_movimientos = "SELECT m.*, p.nombre as producto_nombre, 
                             c.nombre as categoria_nombre,
                             u.nombre as usuario_nombre,
                             CONCAT(pro.nombre, ' ', pro.apellido) as proveedor_nombre
                      FROM movimientos_stock m
                      INNER JOIN productos p ON m.id_producto = p.id
                      INNER JOIN categorias c ON p.id_categoria = c.id
                      INNER JOIN usuarios u ON m.id_usuario = u.id
                      INNER JOIN proveedores pro ON p.id_proveedor = pro.id
                      WHERE m.fecha BETWEEN :fecha_inicio AND :fecha_fin";

$params = [
    ':fecha_inicio' => $fecha_inicio . ' 00:00:00',
    ':fecha_fin' => $fecha_fin . ' 23:59:59'
];

// Aplicar filtros adicionales
if (!empty($tipo_movimiento)) {
    $query_movimientos .= " AND m.tipo = :tipo_movimiento";
    $params[':tipo_movimiento'] = $tipo_movimiento;
}

if (!empty($categoria)) {
    $query_movimientos .= " AND p.id_categoria = :categoria";
    $params[':categoria'] = $categoria;
}

$query_movimientos .= " ORDER BY m.fecha DESC";

// Preparar y ejecutar consulta de movimientos
$stmt_movimientos = $db->prepare($query_movimientos);
foreach ($params as $key => $value) {
    $stmt_movimientos->bindValue($key, $value);
}
$stmt_movimientos->execute();
$movimientos = $stmt_movimientos->fetchAll(PDO::FETCH_ASSOC);

// Consulta para productos con stock bajo
$query_stock_bajo = "SELECT p.*, c.nombre as categoria_nombre, 
                            CONCAT(pro.nombre, ' ', pro.apellido) as proveedor_nombre
                     FROM productos p
                     INNER JOIN categorias c ON p.id_categoria = c.id
                     INNER JOIN proveedores pro ON p.id_proveedor = pro.id
                     WHERE p.stock <= p.stock_minimo AND p.oculto = 0";

if ($stock_bajo) {
    $query_stock_bajo .= " AND p.stock <= p.stock_minimo";
}

$query_stock_bajo .= " ORDER BY p.stock ASC, p.nombre";

$stmt_stock_bajo = $db->prepare($query_stock_bajo);
$stmt_stock_bajo->execute();
$productos_stock_bajo = $stmt_stock_bajo->fetchAll(PDO::FETCH_ASSOC);

// Consulta para estadísticas de inventario
$query_estadisticas = "SELECT 
    COUNT(*) as total_productos,
    SUM(p.stock) as total_stock,
    SUM(CASE WHEN p.stock <= p.stock_minimo THEN 1 ELSE 0 END) as productos_stock_bajo,
    SUM(p.stock * p.costo) as valor_inventario
FROM productos p
WHERE p.oculto = 0";

$stmt_estadisticas = $db->prepare($query_estadisticas);
$stmt_estadisticas->execute();
$estadisticas = $stmt_estadisticas->fetch(PDO::FETCH_ASSOC);

// Consulta para movimientos por tipo (para gráfico)
$query_movimientos_tipo = "SELECT 
    tipo,
    COUNT(*) as cantidad,
    SUM(cantidad) as total_cantidad
FROM movimientos_stock 
WHERE fecha BETWEEN :fecha_inicio AND :fecha_fin
GROUP BY tipo";

$stmt_movimientos_tipo = $db->prepare($query_movimientos_tipo);
$stmt_movimientos_tipo->bindValue(':fecha_inicio', $fecha_inicio . ' 00:00:00');
$stmt_movimientos_tipo->bindValue(':fecha_fin', $fecha_fin . ' 23:59:59');
$stmt_movimientos_tipo->execute();
$movimientos_por_tipo = $stmt_movimientos_tipo->fetchAll(PDO::FETCH_ASSOC);

// Obtener categorías para filtro
$query_categorias = "SELECT * FROM categorias ORDER BY nombre";
$stmt_categorias = $db->prepare($query_categorias);
$stmt_categorias->execute();
$categorias = $stmt_categorias->fetchAll(PDO::FETCH_ASSOC);
?>


<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes de Inventario - Sistema de Joyería</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="assets/css/reportes_inventario.css">

</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="reportes-container">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-12">
                    <div class="reportes-header">
                        <h2><i class="bi bi-boxes me-2"></i>Reportes de Inventario</h2>
                        <p class="mb-0">Análisis y estadísticas del inventario</p>
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="filtros-container">
                        <h5>Filtros de Reporte</h5>
                        <form method="get" action="reportes_inventario.php" class="row g-3">
                            <div class="col-md-3">
                                <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                                <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="fecha_fin" class="form-label">Fecha Fin</label>
                                <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" value="<?php echo $fecha_fin; ?>">
                            </div>
                            <div class="col-md-2">
                                <label for="tipo_movimiento" class="form-label">Tipo Movimiento</label>
                                <select class="form-select" id="tipo_movimiento" name="tipo_movimiento">
                                    <option value="">Todos los tipos</option>
                                    <option value="ENTRADA" <?php echo $tipo_movimiento == 'ENTRADA' ? 'selected' : ''; ?>>Entrada</option>
                                    <option value="SALIDA" <?php echo $tipo_movimiento == 'SALIDA' ? 'selected' : ''; ?>>Salida</option>
                                    <option value="AJUSTE" <?php echo $tipo_movimiento == 'AJUSTE' ? 'selected' : ''; ?>>Ajuste</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="categoria" class="form-label">Categoría</label>
                                <select class="form-select" id="categoria" name="categoria">
                                    <option value="">Todas las categorías</option>
                                    <?php foreach ($categorias as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>" <?php echo $categoria == $cat['id'] ? 'selected' : ''; ?>>
                                            <?php echo $cat['nombre']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="stock_bajo" name="stock_bajo" <?php echo $stock_bajo ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="stock_bajo">
                                        Solo stock bajo
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <button type="submit" class="btn btn-primary">Aplicar Filtros</button>
                                <a href="reportes_inventario.php" class="btn btn-secondary">Restablecer</a>
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
                        <div class="estadistica-titulo">Total de Productos</div>
                        <div class="estadistica-valor"><?php echo $estadisticas['total_productos'] ?? 0; ?></div>
                        <div class="estadistica-descripcion">En inventario</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Valor Total</div>
                        <div class="estadistica-valor">$<?php echo number_format($estadisticas['valor_inventario'] ?? 0, 2); ?></div>
                        <div class="estadistica-descripcion">Del inventario</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Stock Total</div>
                        <div class="estadistica-valor"><?php echo $estadisticas['total_stock'] ?? 0; ?></div>
                        <div class="estadistica-descripcion">Unidades disponibles</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Productos con Stock Bajo</div>
                        <div class="estadistica-valor"><?php echo $estadisticas['productos_stock_bajo'] ?? 0; ?></div>
                        <div class="estadistica-descripcion">Necesitan atención</div>
                    </div>
                </div>
            </div>

            <!-- Gráficos -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="reportes-card">
                        <div class="reportes-card-header">
                            <h5 class="mb-0">Movimientos por Tipo</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="chartMovimientosTipo"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="reportes-card">
                        <div class="reportes-card-header">
                            <h5 class="mb-0">Productos con Stock Bajo</h5>
                        </div>
                        <div class="card-body">
                            <?php if (count($productos_stock_bajo) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>Producto</th>
                                                <th>Categoría</th>
                                                <th>Stock Actual</th>
                                                <th>Stock Mínimo</th>
                                                <th>Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($productos_stock_bajo as $producto): ?>
                                                <tr class="<?php echo $producto['stock'] == 0 ? 'stock-critico' : 'stock-bajo'; ?>">
                                                    <td><?php echo htmlspecialchars($producto['nombre']); ?></td>
                                                    <td><?php echo htmlspecialchars($producto['categoria_nombre']); ?></td>
                                                    <td><?php echo $producto['stock']; ?></td>
                                                    <td><?php echo $producto['stock_minimo']; ?></td>
                                                    <td>
                                                        <?php if ($producto['stock'] == 0): ?>
                                                            <span class="badge bg-danger">Sin Stock</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning">Stock Bajo</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-success">
                                    <i class="bi bi-check-circle"></i> No hay productos con stock bajo.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pestañas para diferentes vistas -->
            <div class="row">
                <div class="col-md-12">
                    <ul class="nav nav-tabs" id="inventarioTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="movimientos-tab" data-bs-toggle="tab" data-bs-target="#movimientos" type="button" role="tab">
                                Movimientos de Stock
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="resumen-tab" data-bs-toggle="tab" data-bs-target="#resumen" type="button" role="tab">
                                Resumen de Productos
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="inventarioTabsContent">
                        <!-- Pestaña de Movimientos -->
                        <div class="tab-pane fade show active" id="movimientos" role="tabpanel">
                            <div class="reportes-card mt-0">
                                <div class="reportes-card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Movimientos de Stock</h5>
                                    <span class="badge bg-primary">Total: <?php echo count($movimientos); ?></span>
                                </div>
                                <div class="card-body">
                                    <?php if (count($movimientos) > 0): ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover table-inventario">
                                                <thead>
                                                    <tr>
                                                        <th>Fecha</th>
                                                        <th>Producto</th>
                                                        <th>Categoría</th>
                                                        <th>Tipo</th>
                                                        <th>Cantidad</th>
                                                        <th>Stock Anterior</th>
                                                        <th>Stock Nuevo</th>
                                                        <th>Motivo</th>
                                                        <th>Usuario</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($movimientos as $movimiento): ?>
                                                        <tr>
                                                            <td><?php echo date('d/m/Y H:i', strtotime($movimiento['fecha'])); ?></td>
                                                            <td><?php echo htmlspecialchars($movimiento['producto_nombre']); ?></td>
                                                            <td><?php echo htmlspecialchars($movimiento['categoria_nombre']); ?></td>
                                                            <td>
                                                                <span class="badge badge-movimiento badge-<?php echo strtolower($movimiento['tipo']); ?>">
                                                                    <?php echo $movimiento['tipo']; ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo $movimiento['cantidad']; ?></td>
                                                            <td><?php echo $movimiento['cantidad_anterior']; ?></td>
                                                            <td><?php echo $movimiento['cantidad_nueva']; ?></td>
                                                            <td><?php echo htmlspecialchars($movimiento['motivo']); ?></td>
                                                            <td><?php echo htmlspecialchars($movimiento['usuario_nombre']); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-info">No hay movimientos que coincidan con los filtros aplicados.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Pestaña de Resumen -->
                        <div class="tab-pane fade" id="resumen" role="tabpanel">
                            <div class="reportes-card mt-0">
                                <div class="reportes-card-header">
                                    <h5 class="mb-0">Resumen de Productos por Categoría</h5>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="chartProductosCategoria"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Datos para gráfico de movimientos por tipo
        const movimientosTipoData = {
            labels: [<?php echo implode(',', array_map(function($v) { return "'" . $v['tipo'] . "'"; }, $movimientos_por_tipo)); ?>],
            datasets: [{
                label: 'Cantidad de Movimientos',
                data: [<?php echo implode(',', array_column($movimientos_por_tipo, 'cantidad')); ?>],
                backgroundColor: [
                    'rgba(40, 167, 69, 0.8)',    // Entrada - verde
                    'rgba(220, 53, 69, 0.8)',    // Salida - rojo
                    'rgba(255, 193, 7, 0.8)'     // Ajuste - amarillo
                ],
                borderColor: [
                    'rgba(40, 167, 69, 1)',
                    'rgba(220, 53, 69, 1)',
                    'rgba(255, 193, 7, 1)'
                ],
                borderWidth: 1
            }]
        };

        // Configuración del gráfico de movimientos por tipo
        const movimientosTipoConfig = {
            type: 'bar',
            data: movimientosTipoData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        };

        // Datos para gráfico de productos por categoría (simulado)
        // En una implementación real, se obtendrían estos datos de la base de datos
        const productosCategoriaData = {
            labels: ['Anillos', 'Collares', 'Relojes', 'Pulseras', 'Aretes'],
            datasets: [{
                label: 'Productos por Categoría',
                data: [45, 32, 28, 21, 18],
                backgroundColor: [
                    'rgba(212, 175, 55, 0.8)',
                    'rgba(192, 192, 192, 0.8)',
                    'rgba(139, 69, 19, 0.8)',
                    'rgba(0, 0, 0, 0.8)',
                    'rgba(255, 215, 0, 0.8)'
                ],
                borderColor: [
                    'rgba(212, 175, 55, 1)',
                    'rgba(192, 192, 192, 1)',
                    'rgba(139, 69, 19, 1)',
                    'rgba(0, 0, 0, 1)',
                    'rgba(255, 215, 0, 1)'
                ],
                borderWidth: 1
            }]
        };

        // Configuración del gráfico de productos por categoría
        const productosCategoriaConfig = {
            type: 'doughnut',
            data: productosCategoriaData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        };

        // Inicializar gráficos cuando el documento esté listo
        document.addEventListener('DOMContentLoaded', function() {
            // Gráfico de movimientos por tipo
            const ctxMovimientosTipo = document.getElementById('chartMovimientosTipo').getContext('2d');
            new Chart(ctxMovimientosTipo, movimientosTipoConfig);

            // Gráfico de productos por categoría
            const ctxProductosCategoria = document.getElementById('chartProductosCategoria').getContext('2d');
            new Chart(ctxProductosCategoria, productosCategoriaConfig);
        });

        // Función para exportar a PDF (simulada)
        function exportarPDF() {
            alert('Funcionalidad de exportación a PDF en desarrollo. Se descargará un reporte en formato PDF.');
            // En una implementación real, aquí se redirigiría a un script que genere el PDF
            window.open('generar_reporte_inventario_pdf.php?fecha_inicio=<?php echo $fecha_inicio; ?>&fecha_fin=<?php echo $fecha_fin; ?>&tipo_movimiento=<?php echo $tipo_movimiento; ?>&categoria=<?php echo $categoria; ?>', '_blank');
        }
    </script>
</body>
</html>