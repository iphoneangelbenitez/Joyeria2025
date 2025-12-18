<?php
// reportes_financieros.php
session_start();
// Solo permitir acceso a Administradores
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'ADM') {
    header("Location: login.php");
    exit();
}

require_once "config/database.php";
$database = new Database();
$db = $database->getConnection();

// --- OBTENER PARAMETROS Y FILTROS ---
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');

// Parámetros para las consultas SQL
$params = [
    ':fi' => $fecha_inicio . ' 00:00:00',
    ':ff' => $fecha_fin . ' 23:59:59'
];

// --- 1. CÁLCULO DE INGRESOS (VENTAS) ---
// Consultamos SOLO la tabla ventas para obtener el dinero real facturado sin duplicados
$sql_ingresos = "
    SELECT SUM(total) as total_ingresos
    FROM ventas 
    WHERE fecha BETWEEN :fi AND :ff 
    AND estado = 'COMPLETADA'
";
$stmt = $db->prepare($sql_ingresos);
$stmt->execute($params);
$row_ingresos = $stmt->fetch(PDO::FETCH_ASSOC);
$ingresos = $row_ingresos['total_ingresos'] ?? 0;

// --- 2. CÁLCULO DE COSTOS (MERCADERÍA) ---
// Consultamos los detalles para saber el costo de adquisición de los productos vendidos
$sql_costos = "
    SELECT SUM(vd.cantidad * p.costo) as total_costos
    FROM venta_detalles vd
    INNER JOIN ventas v ON vd.id_venta = v.id
    INNER JOIN productos p ON vd.id_producto = p.id
    WHERE v.fecha BETWEEN :fi AND :ff 
    AND v.estado = 'COMPLETADA'
";
$stmt = $db->prepare($sql_costos);
$stmt->execute($params);
$row_costos = $stmt->fetch(PDO::FETCH_ASSOC);
$costos = $row_costos['total_costos'] ?? 0;

// --- 3. RESULTADOS FINALES ---
$ganancia = $ingresos - $costos;
$margen   = ($ingresos > 0) ? ($ganancia / $ingresos) * 100 : 0;

// --- 4. DATOS PARA GRÁFICOS (POR CATEGORÍA) ---
$sql_cats = "
    SELECT 
        c.nombre as categoria,
        SUM(vd.cantidad * vd.precio_unitario) as venta_bruta, 
        SUM(vd.cantidad * p.costo) as costo_total
    FROM venta_detalles vd
    INNER JOIN ventas v ON vd.id_venta = v.id
    INNER JOIN productos p ON vd.id_producto = p.id
    INNER JOIN categorias c ON p.id_categoria = c.id
    WHERE v.fecha BETWEEN :fi AND :ff 
    AND v.estado = 'COMPLETADA'
    GROUP BY c.id
    ORDER BY venta_bruta DESC
";
$stmt_cats = $db->prepare($sql_cats);
$stmt_cats->execute($params);
$data_categorias = $stmt_cats->fetchAll(PDO::FETCH_ASSOC);

// --- 5. EVOLUCIÓN DIARIA ---
$sql_dias = "
    SELECT 
        DATE(v.fecha) as dia,
        SUM(vd.precio_unitario * vd.cantidad) as ingresos_aprox,
        SUM(vd.cantidad * p.costo) as costos
    FROM venta_detalles vd
    INNER JOIN ventas v ON vd.id_venta = v.id
    INNER JOIN productos p ON vd.id_producto = p.id
    WHERE v.fecha BETWEEN :fi AND :ff 
    AND v.estado = 'COMPLETADA'
    GROUP BY DATE(v.fecha)
    ORDER BY dia ASC
";
$stmt_dias = $db->prepare($sql_dias);
$stmt_dias->execute($params);
$data_dias = $stmt_dias->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte Financiero - Joyería</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="assets/css/theme-oscuro.css">
    <link rel="stylesheet" href="assets/css/theme-oscuro-reportes.css">
    
    <style>
        .card-ganancia { border-left: 4px solid #198754; }
        .card-costo { border-left: 4px solid #dc3545; }
        .card-ingreso { border-left: 4px solid #0d6efd; }
    </style>

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
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="reportes-header d-flex justify-content-between align-items-center">
                        <div>
                            <h2><i class="bi bi-cash-coin me-2"></i>Reporte de Ganancias</h2>
                            <p class="mb-0 text-muted">Análisis de rentabilidad (Ventas Completadas)</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="filtros-container">
                        <h5>Filtros de Período</h5>
                        <form method="get" action="reportes_financieros.php" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Desde</label>
                                <input type="date" class="form-control" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Hasta</label>
                                <input type="date" class="form-control" name="fecha_fin" value="<?php echo $fecha_fin; ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <div class="d-grid gap-2 w-100">
                                    <button type="submit" class="btn btn-primary">Filtrar</button>
                                </div>
                            </div>
                            <div class="col-md-4 text-end mt-2 d-flex align-items-end justify-content-end">
                                <button type="button" class="btn btn-success" onclick="exportarPDF()">
                                    <i class="bi bi-file-earmark-pdf me-1"></i> Descargar Reporte
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="estadistica-card card-ingreso">
                        <div class="estadistica-titulo text-primary">Ingresos Totales</div>
                        <div class="estadistica-valor">$<?php echo number_format($ingresos, 2); ?></div>
                        <div class="estadistica-descripcion">Total ventas cobradas</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card card-costo">
                        <div class="estadistica-titulo text-danger">Costo Mercadería</div>
                        <div class="estadistica-valor">$<?php echo number_format($costos, 2); ?></div>
                        <div class="estadistica-descripcion">Costo de reposición</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card card-ganancia">
                        <div class="estadistica-titulo text-success">Ganancia Neta</div>
                        <div class="estadistica-valor">$<?php echo number_format($ganancia, 2); ?></div>
                        <div class="estadistica-descripcion">Utilidad del periodo</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="estadistica-card">
                        <div class="estadistica-titulo text-warning">Margen de Ganancia</div>
                        <div class="estadistica-valor"><?php echo number_format($margen, 1); ?>%</div>
                        <div class="estadistica-descripcion">Rentabilidad promedio</div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="reportes-card h-100">
                        <div class="reportes-card-header">
                            <h5>Evolución Financiera (Ingresos vs Costos)</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container" style="height: 300px;">
                                <canvas id="chartEvolucion"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="reportes-card h-100">
                        <div class="reportes-card-header">
                            <h5>Rentabilidad por Categoría</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container" style="height: 300px;">
                                <canvas id="chartCategorias"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="reportes-card">
                        <div class="reportes-card-header">
                            <h5>Detalle por Categoría de Producto</h5>
                        </div>
                        <div class="card-body table-responsive">
                            <table class="table table-hover table-bordered border-secondary">
                                <thead class="table-light">
                                    <tr>
                                        <th>Categoría</th>
                                        <th class="text-end">Ventas Brutas Est.</th>
                                        <th class="text-end">Costo Total</th>
                                        <th class="text-end">Ganancia Aprox.</th>
                                        <th class="text-center">Rentabilidad</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($data_categorias as $cat): 
                                        $ganancia_cat = $cat['venta_bruta'] - $cat['costo_total'];
                                        $margen_cat = ($cat['venta_bruta'] > 0) ? ($ganancia_cat / $cat['venta_bruta']) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($cat['categoria']); ?></td>
                                        <td class="text-end">$<?php echo number_format($cat['venta_bruta'], 2); ?></td>
                                        <td class="text-end text-danger">$<?php echo number_format($cat['costo_total'], 2); ?></td>
                                        <td class="text-end text-success fw-bold">$<?php echo number_format($ganancia_cat, 2); ?></td>
                                        <td class="text-center">
                                            <span class="badge <?php echo $margen_cat > 30 ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                                <?php echo number_format($margen_cat, 1); ?>%
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <small class="text-muted">* Las ventas brutas por categoría son estimadas antes de descuentos globales en factura.</small>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/boton-oscuro.js"></script>
    
    <script>
        // Función idéntica a reportes_ventas para exportar PDF
        function exportarPDF() {
            const fi = document.querySelector('input[name="fecha_inicio"]').value;
            const ff = document.querySelector('input[name="fecha_fin"]').value;
            
            // Construimos la URL con los parámetros
            const url = `generar_reporte_financiero_pdf.php?fecha_inicio=${fi}&fecha_fin=${ff}`;
            window.open(url, '_blank');
        }

        // --- Datos para Gráficos ---
        
        // 1. Evolución (Gráfico de barras mixto)
        const labelsDia = [<?php echo implode(',', array_map(function($d){ return "'" . date('d/m', strtotime($d['dia'])) . "'"; }, $data_dias)); ?>];
        const dataIngresos = [<?php echo implode(',', array_column($data_dias, 'ingresos_aprox')); ?>];
        const dataCostos = [<?php echo implode(',', array_column($data_dias, 'costos')); ?>];
        // Calculamos la ganancia en JS para el gráfico
        const dataGanancia = dataIngresos.map((val, i) => val - dataCostos[i]);

        if(document.getElementById('chartEvolucion')) {
            new Chart(document.getElementById('chartEvolucion'), {
                type: 'bar',
                data: {
                    labels: labelsDia,
                    datasets: [
                        {
                            label: 'Ingresos',
                            data: dataIngresos,
                            backgroundColor: 'rgba(13, 110, 253, 0.7)', // Azul
                            order: 2
                        },
                        {
                            label: 'Ganancia Neta',
                            data: dataGanancia,
                            type: 'line',
                            borderColor: '#198754', // Verde
                            backgroundColor: '#198754',
                            borderWidth: 3,
                            tension: 0.3,
                            pointRadius: 3,
                            order: 1
                        }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });
        }

        // 2. Categorías (Gráfico de Dona)
        const labelsCat = [<?php echo implode(',', array_map(function($c){ return "'" . $c['categoria'] . "'"; }, $data_categorias)); ?>];
        // Graficamos la ganancia por categoría
        const valuesCat = [<?php echo implode(',', array_map(function($c){ return $c['venta_bruta'] - $c['costo_total']; }, $data_categorias)); ?>];

        if(document.getElementById('chartCategorias')) {
            new Chart(document.getElementById('chartCategorias'), {
                type: 'doughnut',
                data: {
                    labels: labelsCat,
                    datasets: [{
                        data: valuesCat,
                        backgroundColor: ['#ffc107', '#0dcaf0', '#d63384', '#6610f2', '#20c997'],
                        borderWidth: 0
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right' },
                        title: { display: true, text: 'Distribución de Ganancias' }
                    }
                }
            });
        }
    </script>
</body>
</html>