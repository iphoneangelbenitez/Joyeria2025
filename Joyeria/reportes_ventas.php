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

// --- OBTENER PARAMETROS Y FILTROS ---
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01'); // Primer día del mes
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t'); // Último día del mes
$metodo_pago = $_GET['metodo_pago'] ?? '';
$id_vendedor = $_GET['id_vendedor'] ?? '';
$estado_filtro = $_GET['estado'] ?? 'COMPLETADA'; 

// --- CONSTRUCCIÓN DE FILTROS COMUNES (DRY - Don't Repeat Yourself) ---
// Creamos una base de condiciones WHERE para reutilizar en todas las consultas
$condiciones = "v.fecha BETWEEN :fecha_inicio AND :fecha_fin";
$params = [
    ':fecha_inicio' => $fecha_inicio . ' 00:00:00',
    ':fecha_fin' => $fecha_fin . ' 23:59:59'
];

if (!empty($metodo_pago)) {
    $condiciones .= " AND v.metodo_pago = :metodo_pago";
    $params[':metodo_pago'] = $metodo_pago;
}
if (!empty($id_vendedor) && $esAdministrador) {
    $condiciones .= " AND v.id_usuario = :id_vendedor";
    $params[':id_vendedor'] = $id_vendedor;
}
if ($estado_filtro !== 'TODOS') {
    $condiciones .= " AND v.estado = :estado";
    $params[':estado'] = $estado_filtro;
}

// --- 1. ESTADÍSTICAS FINANCIERAS (SOLUCIÓN AL ERROR DE SUMA) ---
// Consultamos SOLO la tabla ventas para evitar duplicar el dinero si hay multiples items
$query_finanzas = "SELECT 
    COUNT(v.id) as total_ventas,
    SUM(v.total) as ingresos_totales,
    AVG(v.total) as promedio_venta,
    SUM(v.descuento) as descuentos_totales
FROM ventas v
WHERE $condiciones";

$stmt_finanzas = $db->prepare($query_finanzas);
foreach ($params as $key => $value) { $stmt_finanzas->bindValue($key, $value); }
$stmt_finanzas->execute();
$stats_finanzas = $stmt_finanzas->fetch(PDO::FETCH_ASSOC);

// --- 2. ESTADÍSTICAS DE PRODUCTOS (SEPARADA) ---
// Consultamos detalles para saber la cantidad de items
$query_productos = "SELECT SUM(vd.cantidad) as productos_vendidos
FROM venta_detalles vd
INNER JOIN ventas v ON vd.id_venta = v.id
WHERE $condiciones";

$stmt_prods = $db->prepare($query_productos);
foreach ($params as $key => $value) { $stmt_prods->bindValue($key, $value); }
$stmt_prods->execute();
$stats_productos = $stmt_prods->fetch(PDO::FETCH_ASSOC);

// Unimos los resultados para usarlos fácil en el HTML
$estadisticas = array_merge($stats_finanzas, $stats_productos);


// --- 3. LISTADO DE VENTAS (TABLA) ---
$query_ventas = "SELECT v.*, c.nombre as cliente_nombre, c.apellido as cliente_apellido, 
                        u.nombre as vendedor_nombre, COUNT(vd.id) as items
                 FROM ventas v
                 INNER JOIN clientes c ON v.id_cliente = c.id
                 INNER JOIN usuarios u ON v.id_usuario = u.id
                 LEFT JOIN venta_detalles vd ON v.id = vd.id_venta
                 WHERE $condiciones
                 GROUP BY v.id ORDER BY v.fecha DESC";

$stmt_ventas = $db->prepare($query_ventas);
foreach ($params as $key => $value) { $stmt_ventas->bindValue($key, $value); }
$stmt_ventas->execute();
$ventas = $stmt_ventas->fetchAll(PDO::FETCH_ASSOC);


// --- 4. GRÁFICO: MÉTODOS DE PAGO ---
$query_metodos = "SELECT metodo_pago, COUNT(*) as cantidad, SUM(total) as monto_total
                  FROM ventas v
                  WHERE $condiciones
                  GROUP BY metodo_pago";

$stmt_metodos = $db->prepare($query_metodos);
foreach ($params as $key => $value) { $stmt_metodos->bindValue($key, $value); }
$stmt_metodos->execute();
$metodos_pago = $stmt_metodos->fetchAll(PDO::FETCH_ASSOC);


// --- 5. GRÁFICO: VENTAS POR DÍA ---
$query_dias = "SELECT DATE(fecha) as dia, COUNT(*) as cantidad_ventas, SUM(total) as monto_total
               FROM ventas v
               WHERE $condiciones
               GROUP BY DATE(fecha) ORDER BY dia";

$stmt_dias = $db->prepare($query_dias);
foreach ($params as $key => $value) { $stmt_dias->bindValue($key, $value); }
$stmt_dias->execute();
$ventas_por_dia = $stmt_dias->fetchAll(PDO::FETCH_ASSOC);


// --- OBTENER VENDEDORES ---
$vendedores = [];
if ($esAdministrador) {
    $stmt_v = $db->query("SELECT id, nombre FROM usuarios WHERE tipo = 'USER' ORDER BY nombre");
    $vendedores = $stmt_v->fetchAll(PDO::FETCH_ASSOC);
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
                        <h2><i class="bi bi-graph-up me-2"></i>Reportes de Ventas</h2>
                        <p class="mb-0">Análisis y estadísticas de ventas</p>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="filtros-container">
                        <h5>Filtros de Reporte</h5>
                        <form method="get" action="reportes_ventas.php" class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label">Desde</label>
                                <input type="date" class="form-control" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Hasta</label>
                                <input type="date" class="form-control" name="fecha_fin" value="<?php echo $fecha_fin; ?>">
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Estado</label>
                                <select class="form-select" name="estado">
                                    <option value="COMPLETADA" <?php echo $estado_filtro == 'COMPLETADA' ? 'selected' : ''; ?>>Solo Concretadas</option>
                                    <option value="ANULADA" <?php echo $estado_filtro == 'ANULADA' ? 'selected' : ''; ?>>Solo Anuladas</option>
                                    <option value="TODOS" <?php echo $estado_filtro == 'TODOS' ? 'selected' : ''; ?>>Todas</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Método Pago</label>
                                <select class="form-select" name="metodo_pago">
                                    <option value="">Todos</option>
                                    <option value="EFECTIVO" <?php echo $metodo_pago == 'EFECTIVO' ? 'selected' : ''; ?>>Efectivo</option>
                                    <option value="TARJETA" <?php echo $metodo_pago == 'TARJETA' ? 'selected' : ''; ?>>Tarjeta</option>
                                    <option value="TRANSFERENCIA" <?php echo $metodo_pago == 'TRANSFERENCIA' ? 'selected' : ''; ?>>Transferencia</option>
                                </select>
                            </div>

                            <?php if ($esAdministrador): ?>
                            <div class="col-md-2">
                                <label class="form-label">Vendedor</label>
                                <select class="form-select" name="id_vendedor">
                                    <option value="">Todos</option>
                                    <?php foreach ($vendedores as $v): ?>
                                        <option value="<?php echo $v['id']; ?>" <?php echo $id_vendedor == $v['id'] ? 'selected' : ''; ?>>
                                            <?php echo $v['nombre']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="col-md-2 d-flex align-items-end">
                                <div class="d-grid gap-2 w-100">
                                    <button type="submit" class="btn btn-primary">Filtrar</button>
                                </div>
                            </div>
                            
                            <div class="col-12 text-end mt-2">
                                <a href="reportes_ventas.php" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                                <button type="button" class="btn btn-sm btn-success" onclick="exportarPDF()">
                                    <i class="bi bi-file-earmark-pdf"></i> PDF
                                </button>
                             </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Total Ventas</div>
                        <div class="estadistica-valor"><?php echo $estadisticas['total_ventas'] ?? 0; ?></div>
                        <div class="estadistica-descripcion">
                            <?php echo ($estado_filtro == 'COMPLETADA') ? 'Ventas concretadas' : 'Según filtros'; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Ingresos</div>
                        <div class="estadistica-valor">$<?php echo number_format($estadisticas['ingresos_totales'] ?? 0, 2); ?></div>
                        <div class="estadistica-descripcion">
                             <?php echo ($estado_filtro == 'COMPLETADA') ? 'Dinero real ingresado' : 'Monto total'; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Ticket Promedio</div>
                        <div class="estadistica-valor">$<?php echo number_format($estadisticas['promedio_venta'] ?? 0, 2); ?></div>
                        <div class="estadistica-descripcion">Promedio por venta</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Productos</div>
                        <div class="estadistica-valor"><?php echo $estadisticas['productos_vendidos'] ?? 0; ?></div>
                        <div class="estadistica-descripcion">Unidades movidas</div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="reportes-card h-100">
                        <div class="reportes-card-header">
                            <h5 class="mb-0">Evolución Diaria (<?php echo strtolower($estado_filtro); ?>)</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="chartVentasPorDia"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="reportes-card h-100">
                        <div class="reportes-card-header">
                            <h5 class="mb-0">Métodos de Pago</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="chartMetodosPago"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <div class="reportes-card">
                        <div class="reportes-card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Detalle de Operaciones</h5>
                            <span class="badge bg-dark">Total: <?php echo count($ventas); ?></span>
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
                                                <th>Estado</th>
                                                <th>Items</th>
                                                <th>Descuento</th>
                                                <th>Total</th>
                                                <th>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($ventas as $venta): ?>
                                                <tr class="<?php echo ($venta['estado'] === 'ANULADA') ? 'table-danger' : ''; ?>">
                                                    <td>#<?php echo $venta['id']; ?></td>
                                                    <td><?php echo date('d/m/Y H:i', strtotime($venta['fecha'])); ?></td>
                                                    <td><?php echo htmlspecialchars($venta['cliente_apellido'] . ', ' . $venta['cliente_nombre']); ?></td>
                                                    <td><?php echo htmlspecialchars($venta['vendedor_nombre']); ?></td>
                                                    
                                                    <td>
                                                        <span class="badge badge-venta badge-<?php echo strtolower($venta['metodo_pago']); ?>">
                                                            <?php echo $venta['metodo_pago']; ?>
                                                        </span>
                                                    </td>

                                                    <td>
                                                        <?php if ($venta['estado'] === 'ANULADA'): ?>
                                                            <span class="badge bg-danger">ANULADA</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-success">COMPLETADA</span>
                                                        <?php endif; ?>
                                                    </td>

                                                    <td><?php echo $venta['items']; ?></td>
                                                    <td><?php echo $venta['descuento']; ?>%</td>
                                                    <td><strong>$<?php echo number_format($venta['total'], 2); ?></strong></td>
                                                    
                                                    <td>
                                                        <a href="generar_pdf.php?id_venta=<?php echo $venta['id']; ?>" class="btn btn-sm btn-outline-primary" title="Ver Factura" target="_blank">
                                                            <i class="bi bi-receipt"></i>
                                                        </a>

                                                        <?php if ($esAdministrador && $venta['estado'] !== 'ANULADA'): ?>
                                                            <button type="button" class="btn btn-sm btn-outline-danger ms-1" 
                                                                    title="Anular Venta"
                                                                    onclick="confirmarAnulacion(<?php echo $venta['id']; ?>)">
                                                                <i class="bi bi-slash-circle"></i>
                                                            </button>
                                                        <?php endif; ?>
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

    <div class="modal fade" id="modalAnular" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content bg-dark text-white"> 
          <div class="modal-header">
            <h5 class="modal-title">Anular Venta / Generar Nota Crédito</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <form action="anular_venta.php" method="POST">
              <div class="modal-body">
                <input type="hidden" name="id_venta" id="idVentaAnular">
                <p>¿Estás seguro de anular esta venta? <strong>El stock será devuelto al inventario.</strong></p>
                <div class="mb-3">
                    <label class="form-label">Motivo de la anulación:</label>
                    <textarea name="motivo" class="form-control" required placeholder="Ej: Producto defectuoso, Cliente se arrepintió..."></textarea>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger">Confirmar Anulación</button>
              </div>
          </form>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/boton-oscuro.js"></script>

    <script>
        function confirmarAnulacion(idVenta) {
            document.getElementById('idVentaAnular').value = idVenta;
            var myModal = new bootstrap.Modal(document.getElementById('modalAnular'));
            myModal.show();
        }

        function exportarPDF() {
            // Se toman los valores de los inputs del formulario
            const fi = document.querySelector('input[name="fecha_inicio"]').value;
            const ff = document.querySelector('input[name="fecha_fin"]').value;
            const mp = document.querySelector('select[name="metodo_pago"]').value;
            const est = document.querySelector('select[name="estado"]').value;
            
            // Para el vendedor, verificamos si existe el elemento (por si no es admin)
            const vendedorSelect = document.querySelector('select[name="id_vendedor"]');
            const vend = vendedorSelect ? vendedorSelect.value : '';

            const url = `generar_reporte_pdf.php?fecha_inicio=${fi}&fecha_fin=${ff}&metodo_pago=${mp}&id_vendedor=${vend}&estado=${est}`;
            window.open(url, '_blank');
        }

        // --- Gráficos JS ---
        // Se inyectan los datos de PHP a JS de forma segura para los arrays
        const ventasLabels = [<?php echo implode(',', array_map(function($v) { return "'" . date('d/m', strtotime($v['dia'])) . "'"; }, $ventas_por_dia)); ?>];
        const ventasData = [<?php echo implode(',', array_column($ventas_por_dia, 'monto_total')); ?>];

        const metodosLabels = [<?php echo implode(',', array_map(function($v) { return "'" . $v['metodo_pago'] . "'"; }, $metodos_pago)); ?>];
        const metodosData = [<?php echo implode(',', array_column($metodos_pago, 'monto_total')); ?>];

        document.addEventListener('DOMContentLoaded', function() {
            // Grafico de linea
            if(document.getElementById('chartVentasPorDia')) {
                new Chart(document.getElementById('chartVentasPorDia'), {
                    type: 'line',
                    data: {
                        labels: ventasLabels,
                        datasets: [{
                            label: 'Monto de Ventas ($)',
                            data: ventasData,
                            backgroundColor: 'rgba(212, 175, 55, 0.2)',
                            borderColor: 'rgba(212, 175, 55, 1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: true
                        }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
                });
            }

            // Grafico de dona
            if(document.getElementById('chartMetodosPago')) {
                new Chart(document.getElementById('chartMetodosPago'), {
                    type: 'doughnut',
                    data: {
                        labels: metodosLabels,
                        datasets: [{
                            data: metodosData,
                            backgroundColor: ['#198754', '#0d6efd', '#6c757d', '#ffc107'],
                            borderWidth: 0
                        }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
                });
            }
        });
    </script>
</body>
</html>