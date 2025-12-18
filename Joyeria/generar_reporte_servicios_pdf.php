<?php
// generar_reporte_servicios_pdf.php
session_start();
require_once 'vendor/autoload.php';
require_once "config/database.php";

// 1. Validar sesión
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// 2. Obtener filtros desde la URL
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');
$estado_filtro = $_GET['estado'] ?? 'TODOS';

// 3. Construir Consulta SQL (Misma lógica que en la vista)
$sql = "SELECT s.*, c.nombre as cliente_nombre, c.apellido as cliente_apellido
        FROM servicios s
        INNER JOIN clientes c ON s.id_cliente = c.id
        WHERE s.fecha_ingreso BETWEEN :fecha_inicio AND :fecha_fin";

$params = [
    ':fecha_inicio' => $fecha_inicio . ' 00:00:00',
    ':fecha_fin' => $fecha_fin . ' 23:59:59'
];

// --- AQUI ESTA LA CORRECCIÓN CLAVE ---
if ($estado_filtro !== 'TODOS') {
    if ($estado_filtro === 'PAGADOS_ENTREGADOS') {
        // Lógica especial para sumar ambos estados
        $sql .= " AND s.estado IN ('PAGADO', 'ENTREGADO')";
    } else {
        // Filtro normal
        $sql .= " AND s.estado = :estado";
        $params[':estado'] = $estado_filtro;
    }
}

$sql .= " ORDER BY s.fecha_ingreso DESC";

$stmt = $db->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Calcular Totales para el Resumen
$total_ingresos = 0;
$cantidad_servicios = count($servicios);

foreach ($servicios as $s) {
    if (in_array($s['estado'], ['PAGADO', 'ENTREGADO'])) {
        $total_ingresos += (float)$s['costo_servicio'];
    }
}

// 5. Configuración de mPDF
$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4-L', // Apaisado (Landscape) para que entre mejor la tabla
    'margin_left' => 10,
    'margin_right' => 10,
    'margin_top' => 10,
    'margin_bottom' => 10
]);

// 6. Estilos CSS
$css = '
<style>
    body { font-family: sans-serif; font-size: 10pt; color: #333; }
    h1 { text-align: center; color: #000; margin-bottom: 5px; }
    .subtitulo { text-align: center; color: #666; font-size: 10pt; margin-bottom: 20px; }
    
    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    th { background-color: #333; color: #fff; padding: 8px; font-size: 9pt; border: 1px solid #333; }
    td { border: 1px solid #ccc; padding: 6px; font-size: 9pt; }
    
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .badge { padding: 2px 5px; border-radius: 3px; font-weight: bold; color: #fff; font-size: 8pt; }
    
    .bg-PENDIENTE { background-color: #6c757d; }
    .bg-EN_PROCESO { background-color: #0d6efd; }
    .bg-TERMINADO { background-color: #ffc107; color: #000; }
    .bg-PAGADO { background-color: #0dcaf0; color: #000; }
    .bg-ENTREGADO { background-color: #198754; }
    .bg-CANCELADO { background-color: #dc3545; }
    
    .resumen { margin-top: 20px; border: 1px solid #000; padding: 10px; width: 300px; float: right; }
</style>
';

// 7. Contenido HTML
$html = '
    <h1>Reporte de Servicios de Taller</h1>
    <div class="subtitulo">
        Periodo: ' . date('d/m/Y', strtotime($fecha_inicio)) . ' al ' . date('d/m/Y', strtotime($fecha_fin)) . '<br>
        Filtro de Estado: ' . htmlspecialchars(str_replace('_', ' ', $estado_filtro)) . '
    </div>

    <table>
        <thead>
            <tr>
                <th width="5%">ID</th>
                <th width="10%">Fecha</th>
                <th width="20%">Cliente</th>
                <th width="15%">Tipo</th>
                <th width="20%">Producto</th>
                <th width="20%">Descripción</th>
                <th width="10%">Estado</th>
                <th width="10%">Costo</th>
            </tr>
        </thead>
        <tbody>';

if (count($servicios) > 0) {
    foreach ($servicios as $s) {
        $estado = strtoupper($s['estado']);
        $clase_bg = 'bg-' . str_replace(' ', '_', $estado); // Para manejar espacios si los hay
        
        $html .= '
            <tr>
                <td class="text-center">#' . $s['id'] . '</td>
                <td class="text-center">' . date('d/m/y', strtotime($s['fecha_ingreso'])) . '</td>
                <td>' . htmlspecialchars($s['cliente_apellido'] . ' ' . $s['cliente_nombre']) . '</td>
                <td class="text-center">' . htmlspecialchars($s['tipo']) . '</td>
                <td>' . htmlspecialchars($s['producto']) . '</td>
                <td><small>' . htmlspecialchars(substr($s['descripcion'], 0, 50)) . '...</small></td>
                <td class="text-center"><span class="badge ' . $clase_bg . '">' . $s['estado'] . '</span></td>
                <td class="text-right">$' . number_format($s['costo_servicio'], 2) . '</td>
            </tr>';
    }
} else {
    $html .= '<tr><td colspan="8" class="text-center">No se encontraron registros con los filtros seleccionados.</td></tr>';
}

$html .= '
        </tbody>
    </table>

    <div class="resumen">
        <b>Resumen del Periodo</b><br>
        <hr>
        Total de Servicios: <b>' . $cantidad_servicios . '</b><br>
        Ingresos Recaudados (Pag/Ent): <b>$' . number_format($total_ingresos, 2) . '</b>
    </div>
';

// 8. Generar PDF
$mpdf->WriteHTML($css . $html);
$mpdf->Output('Reporte_Servicios_' . date('Y-m-d') . '.pdf', 'I');
?>