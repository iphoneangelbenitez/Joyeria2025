<?php
// reportes_servicios.php
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
$tipo_servicio = $_GET['tipo_servicio'] ?? '';
$estado_servicio = $_GET['estado_servicio'] ?? '';
$dni_cliente = $_GET['dni_cliente'] ?? '';

// Construir consulta base para servicios
// (La consulta ya incluye s.fecha_completado, lo cual es necesario para el cambio)
$query_servicios = "SELECT s.*, c.nombre as cliente_nombre, c.apellido as cliente_apellido, 
                           c.dni as cliente_dni, c.telefono as cliente_telefono,
                           DATEDIFF(s.fecha_entrega_estimada, s.fecha_ingreso) as dias_estimados,
                           DATEDIFF(COALESCE(s.fecha_completado, NOW()), s.fecha_ingreso) as dias_reales
                    FROM servicios s
                    INNER JOIN clientes c ON s.id_cliente = c.id
                    WHERE s.fecha_ingreso BETWEEN :fecha_inicio AND :fecha_fin";

$params = [
    ':fecha_inicio' => $fecha_inicio . ' 00:00:00',
    ':fecha_fin' => $fecha_fin . ' 23:59:59'
];

// Aplicar filtros adicionales
if (!empty($tipo_servicio)) {
    $query_servicios .= " AND s.tipo = :tipo_servicio";
    $params[':tipo_servicio'] = $tipo_servicio;
}

if (!empty($estado_servicio)) {
    $query_servicios .= " AND s.estado = :estado_servicio";
    $params[':estado_servicio'] = $estado_servicio;
}

if (!empty($dni_cliente)) {
    $query_servicios .= " AND c.dni LIKE :dni_cliente";
    $params[':dni_cliente'] = "%$dni_cliente%";
}

$query_servicios .= " ORDER BY s.fecha_ingreso DESC";

// Preparar y ejecutar consulta de servicios
$stmt_servicios = $db->prepare($query_servicios);
foreach ($params as $key => $value) {
    $stmt_servicios->bindValue($key, $value);
}
$stmt_servicios->execute();
$servicios = $stmt_servicios->fetchAll(PDO::FETCH_ASSOC);

// Consulta para estadísticas de servicios
$query_estadisticas = "SELECT 
    COUNT(*) as total_servicios,
    SUM(s.costo_servicio) as ingresos_servicios,
    AVG(s.costo_servicio) as promedio_costo,
    COUNT(CASE WHEN s.estado = 'COMPLETADO' THEN 1 END) as servicios_completados,
    COUNT(CASE WHEN s.estado = 'PENDIENTE' THEN 1 END) as servicios_pendientes,
    COUNT(CASE WHEN s.estado = 'EN_PROCESO' THEN 1 END) as servicios_proceso,
    COUNT(CASE WHEN s.tipo = 'MANTENIMIENTO' THEN 1 END) as mantenimientos,
    COUNT(CASE WHEN s.tipo = 'REPARACION' THEN 1 END) as reparaciones
FROM servicios s
WHERE s.fecha_ingreso BETWEEN :fecha_inicio AND :fecha_fin";

$params_estadisticas = [
    ':fecha_inicio' => $fecha_inicio . ' 00:00:00',
    ':fecha_fin' => $fecha_fin . ' 23:59:59'
];

if (!empty($tipo_servicio)) {
    $query_estadisticas .= " AND s.tipo = :tipo_servicio";
    $params_estadisticas[':tipo_servicio'] = $tipo_servicio;
}

if (!empty($estado_servicio)) {
    $query_estadisticas .= " AND s.estado = :estado_servicio";
    $params_estadisticas[':estado_servicio'] = $estado_servicio;
}

$stmt_estadisticas = $db->prepare($query_estadisticas);
foreach ($params_estadisticas as $key => $value) {
    $stmt_estadisticas->bindValue($key, $value);
}
$stmt_estadisticas->execute();
$estadisticas = $stmt_estadisticas->fetch(PDO::FETCH_ASSOC);

// Consulta para servicios por estado (para gráfico)
$query_servicios_estado = "SELECT 
    estado,
    COUNT(*) as cantidad,
    SUM(costo_servicio) as ingresos_totales
FROM servicios 
WHERE fecha_ingreso BETWEEN :fecha_inicio AND :fecha_fin
GROUP BY estado";

$stmt_servicios_estado = $db->prepare($query_servicios_estado);
$stmt_servicios_estado->bindValue(':fecha_inicio', $fecha_inicio . ' 00:00:00');
$stmt_servicios_estado->bindValue(':fecha_fin', $fecha_fin . ' 23:59:59');
$stmt_servicios_estado->execute();
$servicios_por_estado = $stmt_servicios_estado->fetchAll(PDO::FETCH_ASSOC);

// Consulta para servicios por tipo (para gráfico)
$query_servicios_tipo = "SELECT 
    tipo,
    COUNT(*) as cantidad,
    SUM(costo_servicio) as ingresos_totales
FROM servicios 
WHERE fecha_ingreso BETWEEN :fecha_inicio AND :fecha_fin
GROUP BY tipo";

$stmt_servicios_tipo = $db->prepare($query_servicios_tipo);
$stmt_servicios_tipo->bindValue(':fecha_inicio', $fecha_inicio . ' 00:00:00');
$stmt_servicios_tipo->bindValue(':fecha_fin', $fecha_fin . ' 23:59:59');
$stmt_servicios_tipo->execute();
$servicios_por_tipo = $stmt_servicios_tipo->fetchAll(PDO::FETCH_ASSOC);

// Consulta para servicios atrasados
$query_servicios_atrasados = "SELECT s.*, c.nombre as cliente_nombre, c.apellido as cliente_apellido,
                                     c.dni as cliente_dni, DATEDIFF(NOW(), s.fecha_entrega_estimada) as dias_atraso
                              FROM servicios s
                              INNER JOIN clientes c ON s.id_cliente = c.id
                              WHERE s.estado != 'COMPLETADO' 
                              AND s.fecha_entrega_estimada < CURDATE()
                              AND s.fecha_ingreso BETWEEN :fecha_inicio AND :fecha_fin
                              ORDER BY s.fecha_entrega_estimada ASC";

$stmt_atrasados = $db->prepare($query_servicios_atrasados);
$stmt_atrasados->bindValue(':fecha_inicio', $fecha_inicio . ' 00:00:00');
$stmt_atrasados->bindValue(':fecha_fin', $fecha_fin . ' 23:59:59');
$stmt_atrasados->execute();
$servicios_atrasados = $stmt_atrasados->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes de Servicios - Sistema de Joyería</title>
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
                        <h2><i class="bi bi-tools me-2"></i>Reportes de Servicios</h2>
                        <p class="mb-0">Análisis y estadísticas de servicios de mantenimiento y reparación</p>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="filtros-container">
                        <h5>Filtros de Reporte</h5>
                        <form method="get" action="reportes_servicios.php" class="row g-3">
                            <div class="col-md-3">
                                <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                                <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="fecha_fin" class="form-label">Fecha Fin</label>
                                <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" value="<?php echo $fecha_fin; ?>">
                            </div>
                            <div class="col-md-2">
                                <label for="tipo_servicio" class="form-label">Tipo de Servicio</label>
                                <select class="form-select" id="tipo_servicio" name="tipo_servicio">
                                    <option value="">Todos los tipos</option>
                                    <option value="MANTENIMIENTO" <?php echo $tipo_servicio == 'MANTENIMIENTO' ? 'selected' : ''; ?>>Mantenimiento</option>
                                    <option value="REPARACION" <?php echo $tipo_servicio == 'REPARACION' ? 'selected' : ''; ?>>Reparación</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="estado_servicio" class="form-label">Estado</label>
                                <select class="form-select" id="estado_servicio" name="estado_servicio">
                                    <option value="">Todos los estados</option>
                                    <option value="PENDIENTE" <?php echo $estado_servicio == 'PENDIENTE' ? 'selected' : ''; ?>>Pendiente</option>
                                    <option value="EN_PROCESO" <?php echo $estado_servicio == 'EN_PROCESO' ? 'selected' : ''; ?>>En Proceso</option>
                                    <option value="COMPLETADO" <?php echo $estado_servicio == 'COMPLETADO' ? 'selected' : ''; ?>>Completado</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="dni_cliente" class="form-label">DNI Cliente</label>
                                <input type="text" class="form-control" id="dni_cliente" name="dni_cliente" value="<?php echo htmlspecialchars($dni_cliente); ?>" placeholder="Buscar por DNI">
                            </div>
                            <div class="col-md-12">
                                <button type="submit" class="btn btn-primary">Aplicar Filtros</button>
                                <a href="reportes_servicios.php" class="btn btn-secondary">Restablecer</a>
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
                        <div class="estadistica-titulo">Total de Servicios</div>
                        <div class="estadistica-valor"><?php echo $estadisticas['total_servicios'] ?? 0; ?></div>
                        <div class="estadistica-descripcion">Período seleccionado</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Ingresos por Servicios</div>
                        <div class="estadistica-valor">$<?php echo number_format($estadisticas['ingresos_servicios'] ?? 0, 2); ?></div>
                        <div class="estadistica-descripcion">Período seleccionado</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Costo Promedio</div>
                        <div class="estadistica-valor">$<?php echo number_format($estadisticas['promedio_costo'] ?? 0, 2); ?></div>
                        <div class="estadistica-descripcion">Por servicio</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo">Servicios Completados</div>
                        <div class="estadistica-valor"><?php echo $estadisticas['servicios_completados'] ?? 0; ?></div>
                        <div class="estadistica-descripcion"><?php echo $estadisticas['total_servicios'] > 0 ? round(($estadisticas['servicios_completados'] / $estadisticas['total_servicios']) * 100, 2) : 0; ?>% del total</div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="reportes-card">
                        <div class="reportes-card-header">
                            <h5 class="mb-0">Servicios por Estado</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="chartServiciosEstado"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="reportes-card">
                        <div class="reportes-card-header">
                            <h5 class="mb-0">Servicios por Tipo</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="chartServiciosTipo"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (count($servicios_atrasados) > 0): ?>
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="reportes-card">
                        <div class="reportes-card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Servicios Atrasados</h5>
                            <span class="badge bg-danger">Total: <?php echo count($servicios_atrasados); ?></span>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover table-servicios">
                                    <thead>
                                        <tr>
                                            <th>Cliente</th>
                                            <th>DNI</th>
                                            <th>Producto</th>
                                            <th>Tipo</th>
                                            <th>Fecha Estimada</th>
                                            <th>Días de Atraso</th>
                                            <th>Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($servicios_atrasados as $servicio): ?>
                                            <tr class="servicio-atrasado">
                                                <td><?php echo htmlspecialchars($servicio['cliente_apellido'] . ', ' . $servicio['cliente_nombre']); ?></td>
                                                <td><?php echo htmlspecialchars($servicio['cliente_dni']); ?></td>
                                                <td><?php echo htmlspecialchars($servicio['producto']); ?></td>
                                                <td>
                                                    <span class="badge-tipo badge-<?php echo strtolower($servicio['tipo']); ?>">
                                                        <?php echo $servicio['tipo'] == 'MANTENIMIENTO' ? 'Mantenimiento' : 'Reparación'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('d/m/Y', strtotime($servicio['fecha_entrega_estimada'])); ?></td>
                                                <td>
                                                    <span class="badge bg-danger">
                                                        <?php echo $servicio['dias_atraso']; ?> días
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge-estado badge-<?php echo strtolower($servicio['estado']); ?>">
                                                        <?php 
                                                        switch ($servicio['estado']) {
                                                            case 'PENDIENTE': echo 'Pendiente'; break;
                                                            case 'EN_PROCESO': echo 'En Proceso'; break;
                                                            case 'COMPLETADO': echo 'Completado'; break;
                                                        }
                                                        ?>
                                                    </span>
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
            <?php endif; ?>

            <div class="row">
                <div class="col-md-12">
                    <div class="reportes-card">
                        <div class="reportes-card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Detalle de Servicios</h5>
                            <span class="badge bg-primary">Total: <?php echo count($servicios); ?></span>
                        </div>
                        <div class="card-body">
                            <?php if (count($servicios) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-servicios">
                                        <thead>
                                            <tr>
                                                <th>Fecha Ingreso</th>
                                                <th>Cliente</th>
                                                <th>DNI</th>
                                                <th>Producto</th>
                                                <th>Tipo</th>
                                                <th>Estado</th>
                                                <th>Entrega</th> <th>Costo</th>
                                                <th>Duración</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($servicios as $servicio): ?>
                                                <tr>
                                                    <td><?php echo date('d/m/Y', strtotime($servicio['fecha_ingreso'])); ?></td>
                                                    <td><?php echo htmlspecialchars($servicio['cliente_apellido'] . ', ' . $servicio['cliente_nombre']); ?></td>
                                                    <td><?php echo htmlspecialchars($servicio['cliente_dni']); ?></td>
                                                    <td><?php echo htmlspecialchars($servicio['producto']); ?></td>
                                                    <td>
                                                        <span class="badge-tipo badge-<?php echo strtolower($servicio['tipo']); ?>">
                                                            <?php echo $servicio['tipo'] == 'MANTENIMIENTO' ? 'Mantenimiento' : 'Reparación'; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge-estado badge-<?php echo strtolower($servicio['estado']); ?>">
                                                            <?php 
                                                            switch ($servicio['estado']) {
                                                                case 'PENDIENTE': echo 'Pendiente'; break;
                                                                case 'EN_PROCESO': echo 'En Proceso'; break;
                                                                case 'COMPLETADO': echo 'Completado'; break;
                                                            }
                                                            ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($servicio['estado'] == 'COMPLETADO' && !empty($servicio['fecha_completado'])): ?>
                                                            <?php echo date('d/m/Y', strtotime($servicio['fecha_completado'])); ?>
                                                        <?php else: ?>
                                                            <em>Pendiente</em>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>$<?php echo number_format($servicio['costo_servicio'], 2); ?></td>
                                                    <td>
                                                        <?php if ($servicio['estado'] == 'COMPLETADO'): ?>
                                                            <?php echo $servicio['dias_reales']; ?> días
                                                        <?php else: ?>
                                                            <em>En curso</em>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">No hay servicios que coincidan con los filtros aplicados.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Datos para gráfico de servicios por estado
        const serviciosEstadoData = {
            labels: [<?php echo implode(',', array_map(function($v) { 
                return "'" . ($v['estado'] == 'PENDIENTE' ? 'Pendiente' : ($v['estado'] == 'EN_PROCESO' ? 'En Proceso' : 'Completado')) . "'"; 
            }, $servicios_por_estado)); ?>],
            datasets: [{
                label: 'Cantidad de Servicios',
                data: [<?php echo implode(',', array_column($servicios_por_estado, 'cantidad')); ?>],
                backgroundColor: [
                    'rgba(255, 193, 7, 0.8)',    // Pendiente - amarillo
                    'rgba(0, 123, 255, 0.8)',    // En Proceso - azul
                    'rgba(40, 167, 69, 0.8)'     // Completado - verde
                ],
                borderColor: [
                    'rgba(255, 193, 7, 1)',
                    'rgba(0, 123, 255, 1)',
                    'rgba(40, 167, 69, 1)'
                ],
                borderWidth: 1
            }]
        };

        // Configuración del gráfico de servicios por estado
        const serviciosEstadoConfig = {
            type: 'doughnut',
            data: serviciosEstadoData,
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
                                return context.label + ': ' + value + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        };

        // Datos para gráfico de servicios por tipo
        const serviciosTipoData = {
            labels: [<?php echo implode(',', array_map(function($v) { 
                return "'" . ($v['tipo'] == 'MANTENIMIENTO' ? 'Mantenimiento' : 'Reparación') . "'"; 
            }, $servicios_por_tipo)); ?>],
            datasets: [{
                label: 'Cantidad de Servicios',
                data: [<?php echo implode(',', array_column($servicios_por_tipo, 'cantidad')); ?>],
                backgroundColor: [
                    'rgba(23, 162, 184, 0.8)',   // Mantenimiento - azul claro
                    'rgba(253, 126, 20, 0.8)'    // Reparación - naranja
                ],
                borderColor: [
                    'rgba(23, 162, 184, 1)',
                    'rgba(253, 126, 20, 1)'
                ],
                borderWidth: 1
            }]
        };

        // Configuración del gráfico de servicios por tipo
        const serviciosTipoConfig = {
            type: 'pie',
            data: serviciosTipoData,
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

        // (Se eliminó el JavaScript del gráfico de clientes)

        // Inicializar gráficos cuando el documento esté listo
        document.addEventListener('DOMContentLoaded', function() {
            // Gráfico de servicios por estado
            const ctxServiciosEstado = document.getElementById('chartServiciosEstado').getContext('2d');
            new Chart(ctxServiciosEstado, serviciosEstadoConfig);

            // Gráfico de servicios por tipo
            const ctxServiciosTipo = document.getElementById('chartServiciosTipo').getContext('2d');
            new Chart(ctxServiciosTipo, serviciosTipoConfig);
            
            // (Se eliminó la inicialización del gráfico de clientes)
        });

        // INICIO FUNCIÓN MODIFICADA: exportarPDF
        function exportarPDF() {
            // 1. Obtener los valores actuales de los filtros desde el formulario
            const fecha_inicio = document.getElementById('fecha_inicio').value;
            const fecha_fin = document.getElementById('fecha_fin').value;
            const tipo_servicio = document.getElementById('tipo_servicio').value;
            const estado_servicio = document.getElementById('estado_servicio').value;
            const dni_cliente = document.getElementById('dni_cliente').value;

            // 2. Construir la URL para el script de generación de PDF
            const url = `generar_reporte_servicios_pdf.php?fecha_inicio=${encodeURIComponent(fecha_inicio)}&fecha_fin=${encodeURIComponent(fecha_fin)}&tipo_servicio=${encodeURIComponent(tipo_servicio)}&estado_servicio=${encodeURIComponent(estado_servicio)}&dni_cliente=${encodeURIComponent(dni_cliente)}`;

            // 3. Abrir la URL en una nueva pestaña
            window.open(url, '_blank');
        }
        // FIN FUNCIÓN MODIFICADA
    </script>
    </script>

<script src="assets/js/boton-oscuro.js"></script>
</body>
</html>