<?php
// reportes_servicios.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Verificar permisos
$esAdministrador = ($_SESSION['user_type'] == 'ADM');

require_once "config/database.php";
$database = new Database();
$db = $database->getConnection();

// --- FILTROS ---
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');
$estado = $_GET['estado'] ?? '';
$tipo_servicio = $_GET['tipo_servicio'] ?? '';

// --- CONSULTA PRINCIPAL DE SERVICIOS REALIZADOS (COBRADOS) ---
// Usamos servicios_ventas como base porque son los que generan ingresos
$query_servicios = "SELECT sv.*, c.nombre as cliente_nombre, c.apellido as cliente_apellido, 
                           u.nombre as vendedor_nombre, 
                           d.descripcion_servicio, s.tipo as tipo_servicio
                    FROM servicios_ventas sv
                    INNER JOIN clientes c ON sv.id_cliente = c.id
                    INNER JOIN usuarios u ON sv.id_usuario = u.id
                    INNER JOIN detalle_servicios_ventas d ON sv.id = d.id_venta_servicio
                    INNER JOIN servicios s ON d.id_servicio = s.id
                    WHERE sv.fecha BETWEEN :fecha_inicio AND :fecha_fin";

$params = [
    ':fecha_inicio' => $fecha_inicio . ' 00:00:00',
    ':fecha_fin' => $fecha_fin . ' 23:59:59'
];

if (!empty($estado)) {
    // Si queremos filtrar por estado actual del servicio (Entregado/Pagado)
    $query_servicios .= " AND s.estado = :estado";
    $params[':estado'] = $estado;
}

if (!empty($tipo_servicio)) {
    $query_servicios .= " AND s.tipo = :tipo_servicio";
    $params[':tipo_servicio'] = $tipo_servicio;
}

$query_servicios .= " ORDER BY sv.fecha DESC";

$stmt = $db->prepare($query_servicios);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->execute();
$lista_servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- ESTADÍSTICAS GENERALES ---
$query_stats = "SELECT 
    COUNT(DISTINCT sv.id) as total_tickets,
    SUM(sv.total) as ingresos_totales,
    AVG(sv.total) as ticket_promedio
FROM servicios_ventas sv
INNER JOIN detalle_servicios_ventas d ON sv.id = d.id_venta_servicio
INNER JOIN servicios s ON d.id_servicio = s.id
WHERE sv.fecha BETWEEN :fecha_inicio AND :fecha_fin";

// Reutilizamos params base
$stmt_stats = $db->prepare($query_stats);
$stmt_stats->bindValue(':fecha_inicio', $fecha_inicio . ' 00:00:00');
$stmt_stats->bindValue(':fecha_fin', $fecha_fin . ' 23:59:59');
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// --- GRÁFICO 1: Ingresos por Tipo de Servicio (Reparación, Mantenimiento, etc) ---
$query_tipos = "SELECT s.tipo, COUNT(*) as cantidad, SUM(sv.total) as total
                FROM servicios_ventas sv
                INNER JOIN detalle_servicios_ventas d ON sv.id = d.id_venta_servicio
                INNER JOIN servicios s ON d.id_servicio = s.id
                WHERE sv.fecha BETWEEN :fecha_inicio AND :fecha_fin
                GROUP BY s.tipo";
$stmt_tipos = $db->prepare($query_tipos);
$stmt_tipos->bindValue(':fecha_inicio', $fecha_inicio . ' 00:00:00');
$stmt_tipos->bindValue(':fecha_fin', $fecha_fin . ' 23:59:59');
$stmt_tipos->execute();
$datos_tipos = $stmt_tipos->fetchAll(PDO::FETCH_ASSOC);

// --- GRÁFICO 2: Servicios por Estado Actual (Cuántos entregados vs pendientes de retiro) ---
$query_estados = "SELECT estado, COUNT(*) as cantidad 
                  FROM servicios 
                  WHERE fecha_ingreso BETWEEN :fecha_inicio AND :fecha_fin 
                  GROUP BY estado";
$stmt_estados = $db->prepare($query_estados);
$stmt_estados->bindValue(':fecha_inicio', $fecha_inicio);
$stmt_estados->bindValue(':fecha_fin', $fecha_fin);
$stmt_estados->execute();
$datos_estados = $stmt_estados->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reportes de Servicios - Joyería</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="assets/css/theme-oscuro.css">
    <link rel="stylesheet" href="assets/css/theme-oscuro-reportes.css">
    <style>
        .card-stat { border-left: 4px solid; }
        .border-ingreso { border-color: #198754; }
        .border-ticket { border-color: #0d6efd; }
        .border-promedio { border-color: #ffc107; }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="reportes-container container-fluid mt-4">
        
        <div class="reportes-header mb-4">
            <h2><i class="bi bi-tools me-2"></i>Reportes de Taller</h2>
            <p class="text-muted">Análisis de reparaciones, mantenimientos y fabricaciones.</p>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-3">
                        <label>Desde</label>
                        <input type="date" class="form-control" name="fecha_inicio" value="<?= $fecha_inicio ?>">
                    </div>
                    <div class="col-md-3">
                        <label>Hasta</label>
                        <input type="date" class="form-control" name="fecha_fin" value="<?= $fecha_fin ?>">
                    </div>
                    <div class="col-md-3">
                        <label>Tipo Servicio</label>
                        <select class="form-select" name="tipo_servicio">
                            <option value="">Todos</option>
                            <option value="REPARACION" <?= $tipo_servicio=='REPARACION'?'selected':'' ?>>Reparación</option>
                            <option value="MANTENIMIENTO" <?= $tipo_servicio=='MANTENIMIENTO'?'selected':'' ?>>Mantenimiento</option>
                            <option value="FABRICACION" <?= $tipo_servicio=='FABRICACION'?'selected':'' ?>>Fabricación</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                        <button type="button" class="btn btn-success" onclick="exportarPDF()">
                            <i class="bi bi-file-pdf"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card card-stat border-ingreso shadow-sm h-100">
                    <div class="card-body">
                        <h6 class="text-muted">Ingresos Totales</h6>
                        <h3 class="mb-0 text-success">$<?= number_format($stats['ingresos_totales'] ?? 0, 2) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-stat border-ticket shadow-sm h-100">
                    <div class="card-body">
                        <h6 class="text-muted">Servicios Cobrados</h6>
                        <h3 class="mb-0 text-primary"><?= $stats['total_tickets'] ?? 0 ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-stat border-promedio shadow-sm h-100">
                    <div class="card-body">
                        <h6 class="text-muted">Ticket Promedio</h6>
                        <h3 class="mb-0 text-warning">$<?= number_format($stats['ticket_promedio'] ?? 0, 2) ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-transparent">Ingresos por Tipo de Servicio</div>
                    <div class="card-body">
                        <canvas id="chartTipos"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-transparent">Estado de Servicios (Periodo)</div>
                    <div class="card-body">
                        <canvas id="chartEstados"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Detalle de Operaciones</h5>
                <span class="badge bg-secondary"><?= count($lista_servicios) ?> Registros</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#Ticket</th>
                                <th>Fecha Pago</th>
                                <th>Cliente</th>
                                <th>Servicio</th>
                                <th>Tipo</th>
                                <th>Método Pago</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($lista_servicios) > 0): ?>
                                <?php foreach ($lista_servicios as $fila): ?>
                                <tr>
                                    <td>#<?= $fila['id'] ?></td>
                                    <td><?= date('d/m/Y', strtotime($fila['fecha'])) ?></td>
                                    <td><?= htmlspecialchars($fila['cliente_nombre'] . ' ' . $fila['cliente_apellido']) ?></td>
                                    <td><?= htmlspecialchars($fila['descripcion_servicio']) ?></td>
                                    <td><span class="badge bg-info text-dark"><?= $fila['tipo_servicio'] ?></span></td>
                                    <td><?= htmlspecialchars($fila['metodo_pago']) ?></td>
                                    <td class="text-end fw-bold">$<?= number_format($fila['total'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center py-3">No hay datos en este rango.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/boton-oscuro.js"></script>
    
    <script>
        // --- GRAFICO 1: TIPOS DE SERVICIO ---
        const ctxTipos = document.getElementById('chartTipos').getContext('2d');
        new Chart(ctxTipos, {
            type: 'doughnut',
            data: {
                labels: [<?= implode(',', array_map(fn($t) => "'".$t['tipo']."'", $datos_tipos)) ?>],
                datasets: [{
                    data: [<?= implode(',', array_column($datos_tipos, 'total')) ?>],
                    backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#dc3545']
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'right' } } }
        });

        // --- GRAFICO 2: ESTADOS ---
        const ctxEstados = document.getElementById('chartEstados').getContext('2d');
        new Chart(ctxEstados, {
            type: 'bar',
            data: {
                labels: [<?= implode(',', array_map(fn($e) => "'".$e['estado']."'", $datos_estados)) ?>],
                datasets: [{
                    label: 'Cantidad',
                    data: [<?= implode(',', array_column($datos_estados, 'cantidad')) ?>],
                    backgroundColor: '#6610f2'
                }]
            },
            options: { responsive: true, scales: { y: { beginAtZero: true } } }
        });

        function exportarPDF() {
            const params = new URLSearchParams({
                fecha_inicio: '<?= $fecha_inicio ?>',
                fecha_fin: '<?= $fecha_fin ?>',
                tipo_servicio: '<?= $tipo_servicio ?>'
            });
            window.open('generar_reporte_servicios_pdf.php?' + params.toString(), '_blank');
        }
    </script>
</body>
</html>