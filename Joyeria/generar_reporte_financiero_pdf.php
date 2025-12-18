<?php
// ARCHIVO: generar_reporte_financiero_pdf.php
require_once __DIR__ . '/vendor/autoload.php';
require_once "config/database.php";

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'ADM') {
    die("Acceso denegado");
}

$database = new Database();
$db = $database->getConnection();

$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');

// Parámetros para las consultas
$params = [
    ':fi' => $fecha_inicio . ' 00:00:00',
    ':ff' => $fecha_fin . ' 23:59:59'
];

// --- 1. DATOS GENERALES (Cajas de arriba) ---
// Ingresos Totales
$sql_ingresos = "SELECT SUM(total) as total_ingresos FROM ventas WHERE fecha BETWEEN :fi AND :ff AND estado = 'COMPLETADA'";
$stmt = $db->prepare($sql_ingresos);
$stmt->execute($params);
$ingresos = $stmt->fetch(PDO::FETCH_ASSOC)['total_ingresos'] ?? 0;

// Costos Totales
$sql_costos = "
    SELECT SUM(vd.cantidad * p.costo) as total_costos
    FROM venta_detalles vd
    INNER JOIN ventas v ON vd.id_venta = v.id
    INNER JOIN productos p ON vd.id_producto = p.id
    WHERE v.fecha BETWEEN :fi AND :ff AND v.estado = 'COMPLETADA'
";
$stmt = $db->prepare($sql_costos);
$stmt->execute($params);
$costos = $stmt->fetch(PDO::FETCH_ASSOC)['total_costos'] ?? 0;

$ganancia = $ingresos - $costos;
$margen   = ($ingresos > 0) ? ($ganancia / $ingresos) * 100 : 0;

// --- 2. DATOS PARA LA TABLA (Desglose Detallado) ---
$sql_cats = "
    SELECT 
        c.nombre as categoria,
        SUM(vd.cantidad) as unidades_vendidas,
        SUM(vd.cantidad * vd.precio_unitario) as venta_bruta,
        SUM(vd.cantidad * p.costo) as costo_total
    FROM venta_detalles vd
    INNER JOIN ventas v ON vd.id_venta = v.id
    INNER JOIN productos p ON vd.id_producto = p.id
    INNER JOIN categorias c ON p.id_categoria = c.id
    WHERE v.fecha BETWEEN :fi AND :ff AND v.estado = 'COMPLETADA'
    GROUP BY c.id
    ORDER BY venta_bruta DESC
";
$stmt_cats = $db->prepare($sql_cats);
$stmt_cats->execute($params);
$categorias = $stmt_cats->fetchAll(PDO::FETCH_ASSOC);

// --- GENERACIÓN PDF ---
$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'margin_left' => 10,
    'margin_right' => 10,
    'margin_top' => 15,
    'margin_bottom' => 15,
]);

// Estilos CSS
$css = '
    body { font-family: sans-serif; color: #333; }
    h1 { color: #2c3e50; text-align: center; margin-bottom: 20px; font-size: 22px; }
    
    .resumen-box { width: 100%; margin-bottom: 30px; border: 1px solid #ddd; padding: 10px; background-color: #f8f9fa; }
    .resumen-item { width: 24%; display: inline-block; text-align: center; border-right: 1px solid #eee; }
    .resumen-item:last-child { border-right: none; }
    .resumen-label { font-size: 10px; color: #666; text-transform: uppercase; margin-bottom: 5px; }
    .resumen-value { font-size: 14px; font-weight: bold; color: #333; }
    
    .text-success { color: #198754; }
    .text-danger { color: #dc3545; }
    .text-primary { color: #0d6efd; }
    
    /* TABLA */
    table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 10px; }
    
    th { 
        background-color: #e9ecef; 
        color: #000; 
        padding: 8px 4px; 
        border-bottom: 2px solid #666; 
        font-weight: bold;
        text-transform: uppercase;
        vertical-align: middle;
    }
    
    td { 
        border-bottom: 1px solid #eee; 
        padding: 8px 4px; 
        text-align: right; 
        vertical-align: middle;
    }
    
    td.left { text-align: left; }
    td.center { text-align: center; }
    
    .row-total td {
        border-top: 2px solid #333;
        background-color: #f1f1f1;
        font-weight: bold;
        font-size: 11px;
    }
    
    .footer { position: fixed; bottom: 0; width: 100%; font-size: 9px; text-align: center; color: #999; border-top: 1px solid #eee; padding-top: 10px; }
';

$html = '
    <h1>Reporte de Rentabilidad Detallado</h1>
    <div style="text-align:center; margin-bottom:20px; font-size:11px; color: #555;">
        Período: ' . date('d/m/Y', strtotime($fecha_inicio)) . ' al ' . date('d/m/Y', strtotime($fecha_fin)) . '
    </div>

    <div class="resumen-box">
        <div class="resumen-item">
            <div class="resumen-label">Ventas Totales</div>
            <div class="resumen-value text-primary">$' . number_format($ingresos, 2) . '</div>
        </div>
        <div class="resumen-item">
            <div class="resumen-label">Costo Mercadería</div>
            <div class="resumen-value text-danger">$' . number_format($costos, 2) . '</div>
        </div>
        <div class="resumen-item">
            <div class="resumen-label">Ganancia Neta</div>
            <div class="resumen-value text-success">$' . number_format($ganancia, 2) . '</div>
        </div>
        <div class="resumen-item">
            <div class="resumen-label">Margen Global</div>
            <div class="resumen-value">' . number_format($margen, 1) . '%</div>
        </div>
    </div>

    <h3>Desglose por Categoría</h3>
    <table cellpadding="0" cellspacing="0">
        <thead>
            <tr>
                <th class="left" width="20%">Categoría</th>
                <th class="center" width="8%">Und.</th>
                <th width="14%">Precio<br>Promedio</th>
                <th width="15%">Total<br>Venta</th>
                <th width="15%">Total<br>Costo</th>
                <th width="15%">Total<br>Ganancia</th>
                <th class="center" width="13%">Margen</th>
            </tr>
        </thead>
        <tbody>';

// Acumuladores
$tbl_suma_unidades = 0;
$tbl_suma_venta = 0;
$tbl_suma_costo = 0;
$tbl_suma_ganancia = 0;

foreach ($categorias as $cat) {
    // Cálculos
    $gan = $cat['venta_bruta'] - $cat['costo_total'];
    $mar = ($cat['venta_bruta'] > 0) ? ($gan / $cat['venta_bruta']) * 100 : 0;
    
    // Precio promedio unitario (Evitar división por cero)
    $precio_promedio = ($cat['unidades_vendidas'] > 0) ? ($cat['venta_bruta'] / $cat['unidades_vendidas']) : 0;
    
    // Sumar totales
    $tbl_suma_unidades += $cat['unidades_vendidas'];
    $tbl_suma_venta += $cat['venta_bruta'];
    $tbl_suma_costo += $cat['costo_total'];
    $tbl_suma_ganancia += $gan;
    
    $html .= '<tr>
        <td class="left">' . htmlspecialchars($cat['categoria']) . '</td>
        <td class="center">' . $cat['unidades_vendidas'] . '</td>
        <td>$' . number_format($precio_promedio, 2) . '</td>
        <td>$' . number_format($cat['venta_bruta'], 2) . '</td>
        <td style="color:#dc3545;">$' . number_format($cat['costo_total'], 2) . '</td>
        <td style="color:#198754; font-weight:bold;">$' . number_format($gan, 2) . '</td>
        <td class="center">' . number_format($mar, 1) . '%</td>
    </tr>';
}

// Cálculo del margen total de la tabla
$tbl_margen_total = ($tbl_suma_venta > 0) ? ($tbl_suma_ganancia / $tbl_suma_venta) * 100 : 0;
// Cálculo del promedio global unitario
$tbl_promedio_total = ($tbl_suma_unidades > 0) ? ($tbl_suma_venta / $tbl_suma_unidades) : 0;

// Fila de Totales
$html .= '<tr class="row-total">
            <td class="left">TOTALES</td>
            <td class="center">' . $tbl_suma_unidades . '</td>
            <td>$' . number_format($tbl_promedio_total, 2) . '</td>
            <td>$' . number_format($tbl_suma_venta, 2) . '</td>
            <td style="color:#b02a37;">$' . number_format($tbl_suma_costo, 2) . '</td>
            <td style="color:#146c43;">$' . number_format($tbl_suma_ganancia, 2) . '</td>
            <td class="center">' . number_format($tbl_margen_total, 1) . '%</td>
          </tr>';

$html .= '</tbody></table>

    <div class="footer">
        Reporte generado automáticamente por Sistema Joyería - ' . date('d/m/Y H:i') . '
    </div>
';

$mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
$mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);
$mpdf->Output('reporte_financiero.pdf', 'I');
?>