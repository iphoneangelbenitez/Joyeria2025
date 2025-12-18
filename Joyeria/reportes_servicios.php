<?php
// reportes_servicios.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once "config/database.php";
$database = new Database();
$db = $database->getConnection();

// --- OBTENER PARÁMETROS Y FILTROS ---
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');
$estado_filtro = $_GET['estado'] ?? 'TODOS';

// --- 1. CONSULTA PRINCIPAL (TABLA) ---
$query_servicios = "SELECT s.*, c.nombre as cliente_nombre, c.apellido as cliente_apellido
                    FROM servicios s
                    INNER JOIN clientes c ON s.id_cliente = c.id
                    WHERE s.fecha_ingreso BETWEEN :fecha_inicio AND :fecha_fin";

$params = [
    ':fecha_inicio' => $fecha_inicio . ' 00:00:00',
    ':fecha_fin' => $fecha_fin . ' 23:59:59'
];

// --- MODIFICACIÓN DE FILTRO DE ESTADO ---
if ($estado_filtro !== 'TODOS') {
    if ($estado_filtro === 'PAGADOS_ENTREGADOS') {
        // Filtro especial compuesto para ver todo lo recaudado
        $query_servicios .= " AND s.estado IN ('PAGADO', 'ENTREGADO')";
    } else {
        // Filtro normal (un solo estado)
        $query_servicios .= " AND s.estado = :estado";
        $params[':estado'] = $estado_filtro;
    }
}

$query_servicios .= " ORDER BY s.fecha_ingreso DESC";

$stmt_servicios = $db->prepare($query_servicios);
foreach ($params as $key => $value) { $stmt_servicios->bindValue($key, $value); }
$stmt_servicios->execute();
$servicios = $stmt_servicios->fetchAll(PDO::FETCH_ASSOC);


// --- 2. ESTADÍSTICAS GENERALES (TARJETAS) ---
// Se mantiene igual, ya que 'ingresos_reales' calcula PAGADO + ENTREGADO siempre
$query_stats = "SELECT 
    COUNT(*) as total_servicios,
    SUM(CASE WHEN estado IN ('PAGADO', 'ENTREGADO') THEN costo_servicio ELSE 0 END) as ingresos_reales,
    COUNT(CASE WHEN estado = 'PENDIENTE' THEN 1 END) as total_pendientes,
    COUNT(CASE WHEN estado = 'TERMINADO' THEN 1 END) as total_terminados
FROM servicios 
WHERE fecha_ingreso BETWEEN :fi AND :ff";

$stmt_stats = $db->prepare($query_stats);
$stmt_stats->execute([':fi' => $fecha_inicio . ' 00:00:00', ':ff' => $fecha_fin . ' 23:59:59']);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);


// --- 3. GRÁFICO: DISTRIBUCIÓN POR ESTADO ---
$query_estados = "SELECT estado, COUNT(*) as cantidad FROM servicios 
                  WHERE fecha_ingreso BETWEEN :fi AND :ff GROUP BY estado";
$stmt_est = $db->prepare($query_estados);
$stmt_est->execute([':fi' => $fecha_inicio . ' 00:00:00', ':ff' => $fecha_fin . ' 23:59:59']);
$data_estados = $stmt_est->fetchAll(PDO::FETCH_ASSOC);


// --- 4. GRÁFICO: EVOLUCIÓN DIARIA ---
$query_dias = "SELECT DATE(fecha_ingreso) as dia, COUNT(*) as cantidad 
               FROM servicios 
               WHERE fecha_ingreso BETWEEN :fi AND :ff 
               GROUP BY DATE(fecha_ingreso) ORDER BY dia";
$stmt_dias = $db->prepare($query_dias);
$stmt_dias->execute([':fi' => $fecha_inicio . ' 00:00:00', ':ff' => $fecha_fin . ' 23:59:59']);
$servicios_por_dia = $stmt_dias->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes de Servicios - Joyería Sosa</title>
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
                        <h2><i class="bi bi-graph-up me-2"></i>Reportes de Servicios</h2>
                        <p class="mb-0">Análisis y estadísticas de reparaciones en taller</p>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="filtros-container">
                        <h5>Filtros de Reporte</h5>
                        <form method="get" action="reportes_servicios.php" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Desde</label>
                                <input type="date" class="form-control" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Hasta</label>
                                <input type="date" class="form-control" name="fecha_fin" value="<?php echo $fecha_fin; ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Estado</label>
                                <select class="form-select" name="estado">
                                    <option value="TODOS" <?php echo $estado_filtro == 'TODOS' ? 'selected' : ''; ?>>Todos los estados</option>
                                    
                                    <option value="PAGADOS_ENTREGADOS" <?php echo $estado_filtro == 'PAGADOS_ENTREGADOS' ? 'selected' : ''; ?>>Pagados y Entregados (Ingresos)</option>
                                    
                                    <option value="PENDIENTE" <?php echo $estado_filtro == 'PENDIENTE' ? 'selected' : ''; ?>>Pendiente</option>
                                    <option value="EN PROCESO" <?php echo $estado_filtro == 'EN PROCESO' ? 'selected' : ''; ?>>En Proceso</option>
                                    <option value="TERMINADO" <?php echo $estado_filtro == 'TERMINADO' ? 'selected' : ''; ?>>Terminado</option>
                                    <option value="PAGADO" <?php echo $estado_filtro == 'PAGADO' ? 'selected' : ''; ?>>Pagado</option>
                                    <option value="ENTREGADO" <?php echo $estado_filtro == 'ENTREGADO' ? 'selected' : ''; ?>>Entregado</option>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <div class="d-grid gap-2 w-100">
                                    <button type="submit" class="btn btn-primary">Filtrar</button>
                                </div>
                            </div>
                            <div class="col-12 text-end mt-2">
                                <a href="reportes_servicios.php" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                                <button type="button" class="btn btn-sm btn-success" onclick="exportarPlanillaPDF()">
                                    <i class="bi bi-file-earmark-pdf"></i> Generar Planilla PDF
                                </button>
                             </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Total Servicios</div>
                        <div class="estadistica-valor"><?php echo $stats['total_servicios'] ?? 0; ?></div>
                        <div class="estadistica-descripcion">Trabajos registrados</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Ingresos Reales</div>
                        <div class="estadistica-valor">$<?php echo number_format($stats['ingresos_reales'] ?? 0, 2); ?></div>
                        <div class="estadistica-descripcion">Solo Pagados/Entregados</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">En Taller</div>
                        <div class="estadistica-valor"><?php echo $stats['total_pendientes'] ?? 0; ?></div>
                        <div class="estadistica-descripcion">Estado Pendiente</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Listos para Entrega</div>
                        <div class="estadistica-valor"><?php echo $stats['total_terminados'] ?? 0; ?></div>
                        <div class="estadistica-descripcion">Pendientes de cobro</div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="reportes-card h-100">
                        <div class="reportes-card-header"><h5 class="mb-0">Evolución Diaria</h5></div>
                        <div class="card-body">
                            <div class="chart-container" style="position: relative; height:300px;">
                                <canvas id="chartServiciosDia"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="reportes-card h-100">
                        <div class="reportes-card-header"><h5 class="mb-0">Estados del Taller</h5></div>
                        <div class="card-body">
                            <div class="chart-container" style="position: relative; height:300px;">
                                <canvas id="chartEstados"></canvas>
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
                            <span class="badge bg-dark">Registros: <?php echo count($servicios); ?></span>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Fecha</th>
                                                <th>Cliente</th>
                                                <th>Servicio</th>
                                                <th>Producto</th>
                                                <th>Estado</th>
                                                <th>Costo</th>
                                                <th>Acciones</th>
                                            </tr>
                                    </thead>
                                    <tbody>
                                            <?php foreach ($servicios as $s): ?>
                                                <tr>
                                                    <td>#<?php echo $s['id']; ?></td>
                                                    <td><?php echo date('d/m/Y', strtotime($s['fecha_ingreso'])); ?></td>
                                                    <td><?php echo htmlspecialchars($s['cliente_apellido'] . ', ' . $s['cliente_nombre']); ?></td>
                                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($s['tipo']); ?></span></td>
                                                    <td><?php echo htmlspecialchars($s['producto']); ?></td>
                                                    <td>
                                                        <?php 
                                                        $clase_estado = 'bg-warning text-dark';
                                                        if($s['estado'] == 'ENTREGADO') $clase_estado = 'bg-success';
                                                        if($s['estado'] == 'TERMINADO') $clase_estado = 'bg-info text-dark';
                                                        if($s['estado'] == 'PAGADO') $clase_estado = 'bg-primary';
                                                        ?>
                                                        <span class="badge <?php echo $clase_estado; ?>"><?php echo $s['estado'] ?? 'PENDIENTE'; ?></span>
                                                    </td>
                                                    <td><strong>$<?php echo number_format($s['costo_servicio'], 2); ?></strong></td>
                                                    <td>
                                                        <a href="generar_ticket_ingreso.php?id=<?php echo $s['id']; ?>" target="_blank" class="btn btn-sm btn-outline-light" title="Ver Ticket">
                                                            <i class="bi bi-receipt"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/boton-oscuro.js"></script>

    <script>
        // FUNCIÓN CORREGIDA PARA EXPORTAR PLANILLA PDF CON FILTROS
        function exportarPlanillaPDF() {
            const fechaIn = document.querySelector('input[name="fecha_inicio"]').value;
            const fechaFin = document.querySelector('input[name="fecha_fin"]').value;
            const estado = document.querySelector('select[name="estado"]').value;
            
            // Aseguramos que el filtro especial también pase al PDF
            const url = `generar_reporte_servicios_pdf.php?fecha_inicio=${fechaIn}&fecha_fin=${fechaFin}&estado=${estado}`;
            window.open(url, '_blank');
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Gráfico de Línea
            new Chart(document.getElementById('chartServiciosDia'), {
                type: 'line',
                data: {
                    labels: [<?php echo implode(',', array_map(function($d){ return "'".date('d/m', strtotime($d['dia']))."'"; }, $servicios_por_dia)); ?>],
                    datasets: [{
                        label: 'Servicios',
                        data: [<?php echo implode(',', array_column($servicios_por_dia, 'cantidad')); ?>],
                        backgroundColor: 'rgba(212, 175, 55, 0.2)',
                        borderColor: 'rgba(212, 175, 55, 1)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });

            // Gráfico de Estados
            new Chart(document.getElementById('chartEstados'), {
                type: 'doughnut',
                data: {
                    labels: [<?php echo implode(',', array_map(function($e){ return "'".$e['estado']."'"; }, $data_estados)); ?>],
                    datasets: [{
                        data: [<?php echo implode(',', array_column($data_estados, 'cantidad')); ?>],
                        backgroundColor: ['#ffc107', '#0dcaf0', '#198754', '#6c757d', '#0d6efd', '#20c997'],
                        borderWidth: 0
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
            });
        });
    </script>
</body>
</html>