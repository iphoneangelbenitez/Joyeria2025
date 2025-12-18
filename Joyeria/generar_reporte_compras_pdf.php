<?php
// generar_reporte_compras_pdf.php
session_start();
require_once 'vendor/autoload.php';
require_once "config/database.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// --- 1. Obtener Filtros ---
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');
$id_proveedor = $_GET['id_proveedor'] ?? '';
$forma_pago = $_GET['forma_pago'] ?? '';
$id_usuario = $_GET['id_usuario'] ?? '';

// --- 2. Consulta SQL (Con Productos concatenados) ---
$sql = "SELECT c.*, 
               p.empresa as proveedor_empresa,
               u.nombre as usuario_nombre,
               COUNT(cd.id) as items,
               COALESCE(SUM(cd.cantidad), 0) as total_unidades,
               GROUP_CONCAT(prod.nombre SEPARATOR ', ') as lista_productos
        FROM compras c
        LEFT JOIN proveedores p ON c.id_proveedor = p.id
        LEFT JOIN usuarios u ON c.id_usuario = u.id
        LEFT JOIN compra_detalles cd ON c.id = cd.id_compra
        LEFT JOIN productos prod ON cd.id_producto = prod.id
        WHERE c.fecha_compra BETWEEN :fi AND :ff";

$params = [':fi' => $fecha_inicio, ':ff' => $fecha_fin];

if (!empty($id_proveedor)) {
    $sql .= " AND c.id_proveedor = :id_prov";
    $params[':id_prov'] = $id_proveedor;
}
if (!empty($forma_pago)) {
    $sql .= " AND c.forma_pago = :fp";
    $params[':fp'] = $forma_pago;
}
if (!empty($id_usuario)) {
    $sql .= " AND c.id_usuario = :id_user";
    $params[':id_user'] = $id_usuario;
}

$sql .= " GROUP BY c.id ORDER BY c.fecha_compra DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$compras = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 3. Calcular Totales Generales ---
$total_dinero = 0;
$total_items = 0;
foreach ($compras as $c) {
    $total_dinero += $c['total'];
    $total_items += $c['total_unidades'];
}

// --- 4. Generar PDF ---
$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4-L', // Landscape
    'margin_left' => 10,
    'margin_right' => 10,
    'margin_top' => 10,
    'margin_bottom' => 10
]);

$css = '
<style>
    body { font-family: sans-serif; font-size: 9pt; color: #333; }
    h1 { text-align: center; margin-bottom: 5px; }
    .subtitulo { text-align: center; color: #666; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; }
    th { background-color: #333; color: #fff; padding: 8px; font-weight: bold; font-size: 9pt; }
    td { border: 1px solid #ccc; padding: 6px; font-size: 9pt; text-align: center; vertical-align: top; }
    .text-left { text-align: left; }
    .text-right { text-align: right; }
    .resumen { float: right; width: 300px; border: 1px solid #000; padding: 10px; margin-top: 20px; }
</style>
';

$html = '
    <h1>Reporte de Compras</h1>
    <div class="subtitulo">
        Periodo: ' . date('d/m/Y', strtotime($fecha_inicio)) . ' al ' . date('d/m/Y', strtotime($fecha_fin)) . '
    </div>

    <table>
        <thead>
            <tr>
                <th width="5%">ID</th>
                <th width="10%">Fecha</th>
                <th width="20%">Proveedor</th>
                <th width="10%">Usuario</th>
                <th width="25%">Productos Adquiridos</th>
                <th width="5%">Cant.</th>
                <th width="10%">Pago</th>
                <th width="15%">Total ($)</th>
            </tr>
        </thead>
        <tbody>';

if (count($compras) > 0) {
    foreach ($compras as $c) {
        $html .= '
            <tr>
                <td>#' . $c['id'] . '</td>
                <td>' . date('d/m/Y', strtotime($c['fecha_compra'])) . '</td>
                <td class="text-left">' . htmlspecialchars($c['proveedor_empresa'] ?? 'N/A') . '</td>
                <td>' . htmlspecialchars($c['usuario_nombre']) . '</td>
                
                <td class="text-left"><small>' . htmlspecialchars($c['lista_productos'] ?? '-') . '</small></td>
                
                <td>' . $c['total_unidades'] . '</td>
                <td>' . $c['forma_pago'] . '</td>
                <td class="text-right">$' . number_format($c['total'], 2) . '</td>
            </tr>';
    }
} else {
    $html .= '<tr><td colspan="8">No se encontraron registros.</td></tr>';
}

$html .= '
        </tbody>
    </table>

    <div class="resumen">
        <b>Resumen General</b><br><hr>
        Total Compras: <b>' . count($compras) . '</b><br>
        Unidades Adquiridas: <b>' . $total_items . '</b><br>
        Monto Total Invertido: <b>$' . number_format($total_dinero, 2) . '</b>
    </div>
';

$mpdf->WriteHTML($css . $html);
$mpdf->Output('Reporte_Compras.pdf', 'I');
?>