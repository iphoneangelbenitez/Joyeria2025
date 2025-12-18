<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once "config/database.php";

session_start();
if (!isset($_SESSION['user_id'])) {
    die("Acceso denegado. Debe iniciar sesión.");
}

$db = new Database();
$con = $db->getConnection();

// --- 1. RECIBIR FILTROS (Incluyendo el nuevo 'estado') ---
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');
$metodo_pago = $_GET['metodo_pago'] ?? '';
$id_vendedor = $_GET['id_vendedor'] ?? '';
$estado_filtro = $_GET['estado'] ?? 'COMPLETADA'; // Por defecto igual que la vista web

// --- 2. CONSTRUIR CONSULTA SQL ---
$sql = "SELECT v.*, c.nombre as cliente_nombre, c.apellido as cliente_apellido, 
               u.nombre as vendedor_nombre
        FROM ventas v
        JOIN clientes c ON v.id_cliente = c.id
        JOIN usuarios u ON v.id_usuario = u.id
        WHERE v.fecha BETWEEN :fi AND :ff";

$params = [
    ':fi' => $fecha_inicio . ' 00:00:00',
    ':ff' => $fecha_fin . ' 23:59:59'
];

// Aplicar Filtro de Estado (¡Esto es lo que faltaba!)
if ($estado_filtro !== 'TODOS') {
    $sql .= " AND v.estado = :estado";
    $params[':estado'] = $estado_filtro;
}

// Aplicar Filtro Método Pago
if (!empty($metodo_pago)) {
    $sql .= " AND v.metodo_pago = :mp";
    $params[':mp'] = $metodo_pago;
}

// Aplicar Filtro Vendedor
if (!empty($id_vendedor)) {
    $sql .= " AND v.id_usuario = :vnd";
    $params[':vnd'] = $id_vendedor;
}

$sql .= " ORDER BY v.fecha DESC";

// --- 3. EJECUTAR CONSULTA ---
$stmt = $con->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->execute();
$ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 4. GENERAR PDF ---
// Usamos orientación 'L' (Landscape/Horizontal) para que quepan mejor las columnas
$mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'orientation' => 'L']);

// Texto del título según el filtro
$texto_estado = "Todas";
if ($estado_filtro === 'COMPLETADA') $texto_estado = "Solo Concretadas";
if ($estado_filtro === 'ANULADA') $texto_estado = "Solo Anuladas";

$html = '
<h2 style="text-align:center;">Reporte de Ventas - Joyería 2025</h2>
<p style="text-align:center;">
    <strong>Periodo:</strong> ' . date('d/m/Y', strtotime($fecha_inicio)) . ' al ' . date('d/m/Y', strtotime($fecha_fin)) . ' <br>
    <strong>Filtro:</strong> ' . $texto_estado . '
</p>
<hr>
<table width="100%" style="border-collapse: collapse; font-family: sans-serif; font-size: 12px;" cellpadding="6">
    <thead>
        <tr style="background-color: #f2f2f2;">
            <th align="left" style="border-bottom: 2px solid #333;">ID</th>
            <th align="left" style="border-bottom: 2px solid #333;">Fecha</th>
            <th align="left" style="border-bottom: 2px solid #333;">Cliente</th>
            <th align="left" style="border-bottom: 2px solid #333;">Vendedor</th>
            <th align="center" style="border-bottom: 2px solid #333;">Método</th>
            <th align="center" style="border-bottom: 2px solid #333;">Estado</th>
            <th align="right" style="border-bottom: 2px solid #333;">Total</th>
        </tr>
    </thead>
    <tbody>';

$total_ingresos = 0;

foreach ($ventas as $v) {
    // Colores visuales para el PDF
    $estilo_estado = "color: #000;";
    $texto_estado_fila = $v['estado'];
    
    if ($v['estado'] === 'ANULADA') {
        $estilo_estado = "color: red; font-weight: bold;";
        // No sumamos al total si está anulada
    } else {
        $estilo_estado = "color: green;";
        $total_ingresos += $v['total']; // Solo sumamos completadas
    }

    $html .= '
    <tr>
        <td style="border-bottom: 1px solid #ccc;">#' . $v['id'] . '</td>
        <td style="border-bottom: 1px solid #ccc;">' . date('d/m/Y H:i', strtotime($v['fecha'])) . '</td>
        <td style="border-bottom: 1px solid #ccc;">' . $v['cliente_nombre'] . ' ' . $v['cliente_apellido'] . '</td>
        <td style="border-bottom: 1px solid #ccc;">' . $v['vendedor_nombre'] . '</td>
        <td align="center" style="border-bottom: 1px solid #ccc;">' . $v['metodo_pago'] . '</td>
        <td align="center" style="border-bottom: 1px solid #ccc; ' . $estilo_estado . '">' . $texto_estado_fila . '</td>
        <td align="right" style="border-bottom: 1px solid #ccc;">$' . number_format($v['total'], 2) . '</td>
    </tr>';
}

$html .= '
    </tbody>
    <tfoot>
        <tr>
            <td colspan="6" align="right" style="padding-top: 15px;"><strong>TOTAL INGRESOS REALES (Solo Completadas):</strong></td>
            <td align="right" style="padding-top: 15px; font-size: 14px;"><strong>$' . number_format($total_ingresos, 2) . '</strong></td>
        </tr>
    </tfoot>
</table>';

$mpdf->WriteHTML($html);
$mpdf->Output('Reporte_Ventas.pdf', 'I');
?>