<?php
// reportes_compras.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Verificar permisos de usuario
$esAdministrador = (isset($_SESSION['tipo_usuario']) && $_SESSION['tipo_usuario'] == 'ADM') || (isset($_SESSION['user_type']) && $_SESSION['user_type'] == 'ADM');

require_once "config/database.php";
$database = new Database();
$db = $database->getConnection();

// Obtener parámetros de filtro
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01'); 
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t'); 
$id_proveedor = $_GET['id_proveedor'] ?? '';
$forma_pago = $_GET['forma_pago'] ?? '';
$id_usuario = $_GET['id_usuario'] ?? '';

// --- MODIFICACIÓN DE CONSULTA: AGREGAR PRODUCTOS ---
$query_compras = "SELECT c.*, 
                          p.nombre as proveedor_nombre, p.apellido as proveedor_apellido, p.empresa,
                          u.nombre as usuario_nombre,
                          COUNT(cd.id) as items,
                          COALESCE(SUM(cd.cantidad), 0) as total_unidades,
                          GROUP_CONCAT(prod.nombre SEPARATOR ', ') as lista_productos
                  FROM compras c
                  LEFT JOIN proveedores p ON c.id_proveedor = p.id
                  LEFT JOIN usuarios u ON c.id_usuario = u.id
                  LEFT JOIN compra_detalles cd ON c.id = cd.id_compra
                  LEFT JOIN productos prod ON cd.id_producto = prod.id
                  WHERE c.fecha_compra BETWEEN :fecha_inicio AND :fecha_fin";

$params = [
    ':fecha_inicio' => $fecha_inicio,
    ':fecha_fin' => $fecha_fin
];

// Aplicar filtros adicionales
if (!empty($id_proveedor)) {
    $query_compras .= " AND c.id_proveedor = :id_proveedor";
    $params[':id_proveedor'] = $id_proveedor;
}

if (!empty($forma_pago)) {
    $query_compras .= " AND c.forma_pago = :forma_pago";
    $params[':forma_pago'] = $forma_pago;
}

if (!empty($id_usuario) && $esAdministrador) {
    $query_compras .= " AND c.id_usuario = :id_usuario";
    $params[':id_usuario'] = $id_usuario;
}

$query_compras .= " GROUP BY c.id ORDER BY c.fecha_compra DESC";

// Preparar y ejecutar consulta de compras
$stmt_compras = $db->prepare($query_compras);
foreach ($params as $key => $value) {
    $stmt_compras->bindValue($key, $value);
}
$stmt_compras->execute();
$compras = $stmt_compras->fetchAll(PDO::FETCH_ASSOC);

// Consulta para estadísticas de compras
$query_estadisticas = "SELECT 
    COUNT(DISTINCT c.id) as total_compras,
    SUM(c.total) as monto_total_compras,
    AVG(c.total) as promedio_compra,
    SUM(cd.cantidad) as total_unidades_compradas,
    COUNT(DISTINCT c.id_proveedor) as proveedores_diferentes
FROM compras c
LEFT JOIN compra_detalles cd ON c.id = cd.id_compra
WHERE c.fecha_compra BETWEEN :fecha_inicio AND :fecha_fin";

$params_estadisticas = [
    ':fecha_inicio' => $fecha_inicio,
    ':fecha_fin' => $fecha_fin
];

if (!empty($id_proveedor)) {
    $query_estadisticas .= " AND c.id_proveedor = :id_proveedor";
    $params_estadisticas[':id_proveedor'] = $id_proveedor;
}

if (!empty($forma_pago)) {
    $query_estadisticas .= " AND c.forma_pago = :forma_pago";
    $params_estadisticas[':forma_pago'] = $forma_pago;
}

$stmt_estadisticas = $db->prepare($query_estadisticas);
foreach ($params_estadisticas as $key => $value) {
    $stmt_estadisticas->bindValue($key, $value);
}
$stmt_estadisticas->execute();
$estadisticas = $stmt_estadisticas->fetch(PDO::FETCH_ASSOC);

// Consulta para compras por forma de pago
$query_formas_pago = "SELECT 
    forma_pago,
    COUNT(*) as cantidad,
    SUM(total) as monto_total
FROM compras 
WHERE fecha_compra BETWEEN :fecha_inicio AND :fecha_fin
GROUP BY forma_pago";

$stmt_formas = $db->prepare($query_formas_pago);
$stmt_formas->bindValue(':fecha_inicio', $fecha_inicio);
$stmt_formas->bindValue(':fecha_fin', $fecha_fin);
$stmt_formas->execute();
$formas_pago = $stmt_formas->fetchAll(PDO::FETCH_ASSOC);

// Consulta para compras por día (para gráfico)
$query_compras_por_dia = "SELECT 
    fecha_compra as dia,
    COUNT(*) as cantidad_compras,
    SUM(total) as monto_total
FROM compras 
WHERE fecha_compra BETWEEN :fecha_inicio AND :fecha_fin
GROUP BY fecha_compra
ORDER BY dia";

$stmt_dias = $db->prepare($query_compras_por_dia);
$stmt_dias->bindValue(':fecha_inicio', $fecha_inicio);
$stmt_dias->bindValue(':fecha_fin', $fecha_fin);
$stmt_dias->execute();
$compras_por_dia = $stmt_dias->fetchAll(PDO::FETCH_ASSOC);

// Consulta para compras por proveedor (para gráfico)
$query_compras_proveedor = "SELECT 
    p.empresa as proveedor_nombre,
    COUNT(*) as cantidad_compras,
    SUM(c.total) as monto_total
FROM compras c
LEFT JOIN proveedores p ON c.id_proveedor = p.id
WHERE c.fecha_compra BETWEEN :fecha_inicio AND :fecha_fin
GROUP BY p.id, p.empresa
ORDER BY monto_total DESC
LIMIT 10";

$stmt_proveedores = $db->prepare($query_compras_proveedor);
$stmt_proveedores->bindValue(':fecha_inicio', $fecha_inicio);
$stmt_proveedores->bindValue(':fecha_fin', $fecha_fin);
$stmt_proveedores->execute();
$compras_por_proveedor = $stmt_proveedores->fetchAll(PDO::FETCH_ASSOC);

// Obtener proveedores para filtro
$query_proveedores = "SELECT id, empresa, nombre, apellido FROM proveedores ORDER BY empresa";
$stmt_proveedores = $db->prepare($query_proveedores);
$stmt_proveedores->execute();
$proveedores = $stmt_proveedores->fetchAll(PDO::FETCH_ASSOC);

// Obtener usuarios para filtro (solo para administradores)
$usuarios = [];
if ($esAdministrador) {
    $query_usuarios = "SELECT id, nombre FROM usuarios WHERE activo = 1 ORDER BY nombre";
    $stmt_usuarios = $db->prepare($query_usuarios);
    $stmt_usuarios->execute();
    $usuarios = $stmt_usuarios->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes de Compras - Sistema de Joyería</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="assets/css/theme-oscuro.css">
    <link rel="stylesheet" href="assets/css/theme-oscuro-reportes.css">

    <script>
    (function() {
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'light') {
            document.documentElement.classList.add('theme-light');
        }
    })();
    </script>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="reportes-container">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-12">
                    <div class="reportes-header">
                        <h2><i class="bi bi-cart-plus me-2"></i>Reportes de Compras</h2>
                        <p class="mb-0">Análisis y estadísticas de compras e ingresos de inventario</p>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="filtros-container">
                        <h5>Filtros de Reporte</h5>
                        <form method="get" action="reportes_compras.php" class="row g-3">
                            <div class="col-md-3">
                                <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                                <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="fecha_fin" class="form-label">Fecha Fin</label>
                                <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" value="<?php echo $fecha_fin; ?>">
                            </div>
                            <div class="col-md-2">
                                <label for="id_proveedor" class="form-label">Proveedor</label>
                                <select class="form-select" id="id_proveedor" name="id_proveedor">
                                    <option value="">Todos los proveedores</option>
                                    <?php foreach ($proveedores as $prov): ?>
                                        <option value="<?php echo $prov['id']; ?>" <?php echo $id_proveedor == $prov['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($prov['empresa']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="forma_pago" class="form-label">Forma de Pago</label>
                                <select class="form-select" id="forma_pago" name="forma_pago">
                                    <option value="">Todas las formas</option>
                                    <option value="EFECTIVO" <?php echo $forma_pago == 'EFECTIVO' ? 'selected' : ''; ?>>Efectivo</option>
                                    <option value="TARJETA" <?php echo $forma_pago == 'TARJETA' ? 'selected' : ''; ?>>Tarjeta</option>
                                    <option value="TRANSFERENCIA" <?php echo $forma_pago == 'TRANSFERENCIA' ? 'selected' : ''; ?>>Transferencia</option>
                                    <option value="CHEQUE" <?php echo $forma_pago == 'CHEQUE' ? 'selected' : ''; ?>>Cheque</option>
                                </select>
                            </div>
                            <?php if ($esAdministrador): ?>
                            <div class="col-md-2">
                                <label for="id_usuario" class="form-label">Usuario</label>
                                <select class="form-select" id="id_usuario" name="id_usuario">
                                    <option value="">Todos los usuarios</option>
                                    <?php foreach ($usuarios as $user): ?>
                                        <option value="<?php echo $user['id']; ?>" <?php echo $id_usuario == $user['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($user['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="col-md-12">
                                <button type="submit" class="btn btn-primary">Aplicar Filtros</button>
                                <a href="reportes_compras.php" class="btn btn-secondary">Restablecer</a>
                                <button type="button" class="btn btn-success" onclick="exportarPDF()">
                                    <i class="bi bi-file-earmark-pdf"></i> Exportar PDF
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Total de Compras</div>
                        <div class="estadistica-valor"><?php echo $estadisticas['total_compras'] ?? 0; ?></div>
                        <div class="estadistica-descripcion">Período seleccionado</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Monto Total</div>
                        <div class="estadistica-valor">$<?php echo number_format($estadisticas['monto_total_compras'] ?? 0, 2); ?></div>
                        <div class="estadistica-descripcion">Período seleccionado</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Promedio por Compra</div>
                        <div class="estadistica-valor">$<?php echo number_format($estadisticas['promedio_compra'] ?? 0, 2); ?></div>
                        <div class="estadistica-descripcion">Período seleccionado</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Unidades Compradas</div>
                        <div class="estadistica-valor"><?php echo $estadisticas['total_unidades_compradas'] ?? 0; ?></div>
                        <div class="estadistica-descripcion">Período seleccionado</div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="reportes-card">
                        <div class="reportes-card-header">
                            <h5 class="mb-0">Compras por Día</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="chartComprasPorDia"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="reportes-card">
                        <div class="reportes-card-header">
                            <h5 class="mb-0">Compras por Forma de Pago</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="chartFormasPago"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="reportes-card">
                        <div class="reportes-card-header">
                            <h5 class="mb-0">Top 10 Proveedores por Monto</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="chartComprasProveedor"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <div class="reportes-card">
                        <div class="reportes-card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Detalle de Compras</h5>
                            <span class="badge bg-dark">Total: <?php echo count($compras); ?></span>
                        </div>
                        <div class="card-body">
                            <?php if (count($compras) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-compras">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Fecha Compra</th>
                                                <th>Proveedor</th>
                                                <th>Usuario</th>
                                                <th>Productos</th> 
                                                <th>Items</th>
                                                <th>Unidades</th>
                                                <th>Total</th>
                                                <th>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($compras as $compra): ?>
                                                <tr>
                                                    <td>#<?php echo $compra['id']; ?></td>
                                                    <td><?php echo date('d/m/Y', strtotime($compra['fecha_compra'])); ?></td>
                                                    <td><?php echo htmlspecialchars($compra['empresa'] ?? $compra['proveedor_nombre'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($compra['usuario_nombre'] ?? 'N/A'); ?></td>
                                                    
                                                    <td>
                                                        <small class="text-muted">
                                                            <?php 
                                                            $prods = $compra['lista_productos'] ?? 'Sin detalle';
                                                            echo (strlen($prods) > 50) ? substr($prods, 0, 50) . '...' : $prods;
                                                            ?>
                                                        </small>
                                                    </td>

                                                    <td><?php echo $compra['items']; ?></td>
                                                    <td><?php echo $compra['total_unidades']; ?></td>
                                                    <td><strong>$<?php echo number_format($compra['total'], 2); ?></strong></td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-outline-info" 
                                                                onclick="verDetalleCompra(<?php echo $compra['id']; ?>)"
                                                                title="Ver Detalles">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <?php if (!empty($compra['imagen_factura'])): ?>
                                                            <a href="uploads/facturas/<?php echo $compra['imagen_factura']; ?>" 
                                                               class="btn btn-sm btn-outline-success" 
                                                               target="_blank"
                                                               title="Ver Factura">
                                                                <i class="bi bi-receipt"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">No hay compras que coincidan con los filtros aplicados.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalDetalleCompra" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detalles de Compra #<span id="modalCompraId"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="detalleCompraContent"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Datos para gráfico de compras por día
        const comprasPorDiaData = {
            labels: [<?php echo implode(',', array_map(function($v) { return "'" . date('d/m', strtotime($v['dia'])) . "'"; }, $compras_por_dia)); ?>],
            datasets: [{
                label: 'Monto de Compras por Día',
                data: [<?php echo implode(',', array_column($compras_por_dia, 'monto_total')); ?>],
                backgroundColor: 'rgba(40, 167, 69, 0.2)',
                borderColor: 'rgba(40, 167, 69, 1)',
                borderWidth: 2,
                tension: 0.3
            }]
        };

        // Configuración del gráfico de compras por día
        const comprasPorDiaConfig = {
            type: 'line',
            data: comprasPorDiaData,
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

        // Datos para gráfico de formas de pago
        const formasPagoData = {
            labels: [<?php echo implode(',', array_map(function($v) { return "'" . $v['forma_pago'] . "'"; }, $formas_pago)); ?>],
            datasets: [{
                data: [<?php echo implode(',', array_column($formas_pago, 'monto_total')); ?>],
                backgroundColor: [
                    'rgba(40, 167, 69, 0.8)',    // Efectivo - verde
                    'rgba(0, 123, 255, 0.8)',    // Tarjeta - azul
                    'rgba(108, 117, 125, 0.8)',  // Transferencia - gris
                    'rgba(255, 193, 7, 0.8)'     // Cheque - amarillo
                ],
                borderColor: [
                    'rgba(40, 167, 69, 1)',
                    'rgba(0, 123, 255, 1)',
                    'rgba(108, 117, 125, 1)',
                    'rgba(255, 193, 7, 1)'
                ],
                borderWidth: 1
            }]
        };

        // Configuración del gráfico de formas de pago
        const formasPagoConfig = {
            type: 'doughnut',
            data: formasPagoData,
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

        // Datos para gráfico de compras por proveedor
        const comprasProveedorData = {
            labels: [<?php echo implode(',', array_map(function($v) { return "'" . $v['proveedor_nombre'] . "'"; }, $compras_por_proveedor)); ?>],
            datasets: [{
                label: 'Monto Total en Compras',
                data: [<?php echo implode(',', array_column($compras_por_proveedor, 'monto_total')); ?>],
                backgroundColor: 'rgba(212, 175, 55, 0.8)',
                borderColor: 'rgba(212, 175, 55, 1)',
                borderWidth: 1
            }]
        };

        // Configuración del gráfico de compras por proveedor
        const comprasProveedorConfig = {
            type: 'bar',
            data: comprasProveedorData,
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
                            callback: function(value) {
                                return '$' + value.toFixed(2);
                            }
                        }
                    }
                }
            }
        };

        // Inicializar gráficos cuando el documento esté listo
        document.addEventListener('DOMContentLoaded', function() {
            const ctxComprasPorDia = document.getElementById('chartComprasPorDia').getContext('2d');
            new Chart(ctxComprasPorDia, comprasPorDiaConfig);

            const ctxFormasPago = document.getElementById('chartFormasPago').getContext('2d');
            new Chart(ctxFormasPago, formasPagoConfig);

            const ctxComprasProveedor = document.getElementById('chartComprasProveedor').getContext('2d');
            new Chart(ctxComprasProveedor, comprasProveedorConfig);
        });

        function verDetalleCompra(idCompra) {
            fetch(`obtener_detalle_compra.php?id_compra=${idCompra}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('modalCompraId').textContent = idCompra;
                        
                        let html = `
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="mb-0">Información de la Compra</h6>
                                        </div>
                                        <div class="card-body">
                                            <table class="table table-sm table-borderless">
                                                <tr>
                                                    <td><strong>Proveedor:</strong></td>
                                                    <td>${data.compra.proveedor_nombre}</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Contacto:</strong></td>
                                                    <td>${data.compra.proveedor_contacto}</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Teléfono:</strong></td>
                                                    <td>${data.compra.proveedor_telefono || 'N/A'}</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Email:</strong></td>
                                                    <td>${data.compra.proveedor_email || 'N/A'}</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Fecha Compra:</strong></td>
                                                    <td>${new Date(data.compra.fecha_compra).toLocaleDateString()}</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Fecha Registro:</strong></td>
                                                    <td>${new Date(data.compra.fecha_registro).toLocaleString()}</td>
                                                </tr>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="mb-0">Información de Pago</h6>
                                        </div>
                                        <div class="card-body">
                                            <table class="table table-sm table-borderless">
                                                <tr>
                                                    <td><strong>Forma de Pago:</strong></td>
                                                    <td><span class="badge bg-dark">${data.compra.forma_pago}</span></td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Total:</strong></td>
                                                    <td><strong>$${parseFloat(data.compra.total).toFixed(2)}</strong></td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Registrado por:</strong></td>
                                                    <td>${data.compra.usuario_nombre}</td>
                                                </tr>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;

                        if (data.compra.observaciones) {
                            html += `
                                <div class="row mb-3">
                                    <div class="col-12">
                                        <div class="card">
                                            <div class="card-header">
                                                <h6 class="mb-0">Observaciones</h6>
                                            </div>
                                            <div class="card-body">
                                                <p class="mb-0">${data.compra.observaciones}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            `;
                        }

                        // Detalles de productos
                        html += `
                            <div class="row mb-4">
                                <div class="col-12">
                                    <div class="card">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0">Productos Comprados</h6>
                                            <span class="badge bg-dark">${data.detalles.length} productos</span>
                                        </div>
                                        <div class="card-body">
                                            <div class="table-responsive">
                                                <table class="table table-sm table-hover">
                                                    <thead>
                                                        <tr>
                                                            <th>Producto</th>
                                                            <th>Categoría</th>
                                                            <th>Cantidad</th>
                                                            <th>Costo Unitario</th>
                                                            <th>Subtotal</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                        `;

                        data.detalles.forEach(detalle => {
                            html += `
                                <tr>
                                    <td>
                                        <strong>${detalle.producto_nombre}</strong>
                                        ${detalle.producto_descripcion ? '<br><small class="text-muted">' + detalle.producto_descripcion + '</small>' : ''}
                                    </td>
                                    <td>${detalle.categoria_nombre}</td>
                                    <td>${detalle.cantidad}</td>
                                    <td>$${parseFloat(detalle.costo_unitario).toFixed(2)}</td>
                                    <td><strong>$${parseFloat(detalle.subtotal).toFixed(2)}</strong></td>
                                </tr>
                            `;
                        });

                        html += `</tbody></table></div></div></div></div></div>`;
                        
                        // (Opcional) Movimientos de stock omitidos en esta vista si no es crítico

                        document.getElementById('detalleCompraContent').innerHTML = html;
                        new bootstrap.Modal(document.getElementById('modalDetalleCompra')).show();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al cargar los detalles de la compra');
                });
        }

        // Función para exportar a PDF
        function exportarPDF() {
            const fecha_inicio = document.getElementById('fecha_inicio').value;
            const fecha_fin = document.getElementById('fecha_fin').value;
            const id_proveedor = document.getElementById('id_proveedor').value;
            const forma_pago = document.getElementById('forma_pago').value;
            const id_usuario = document.getElementById('id_usuario') ? document.getElementById('id_usuario').value : '';
            
            const url = `generar_reporte_compras_pdf.php?fecha_inicio=${fecha_inicio}&fecha_fin=${fecha_fin}&id_proveedor=${id_proveedor}&forma_pago=${forma_pago}&id_usuario=${id_usuario}`;
            
            window.open(url, '_blank');
        }
    </script>

    <script src="assets/js/boton-oscuro.js"></script>
</body>
</html>