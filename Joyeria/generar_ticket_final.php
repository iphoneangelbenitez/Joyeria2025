<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once "config/database.php";

if (!isset($_GET['id'])) {
    die("Error: Falta el ID de la venta.");
}

$id_venta = (int)$_GET['id'];
$baseDeDatos = new Database();
$conexion = $baseDeDatos->getConnection();

// 1. Cabecera de la venta
// CORRECCIÓN: Cambiamos 'u.nombre_completo' por 'u.nombre' (o u.usuario si prefieres)
$sql = "SELECT sv.*, c.nombre, c.apellido, c.dni, u.nombre as vendedor
        FROM servicios_ventas sv
        INNER JOIN clientes c ON sv.id_cliente = c.id
        INNER JOIN usuarios u ON sv.id_usuario = u.id
        WHERE sv.id = :id";

$stmt = $conexion->prepare($sql);
$stmt->bindParam(':id', $id_venta);
$stmt->execute();
$venta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$venta) {
    die("Ticket no encontrado.");
}

// 2. Detalles de la venta
$sqlD = "SELECT * FROM detalle_servicios_ventas WHERE id_venta_servicio = :id";
$stmtD = $conexion->prepare($sqlD);
$stmtD->bindParam(':id', $id_venta);
$stmtD->execute();
$detalles = $stmtD->fetchAll(PDO::FETCH_ASSOC);

// Configuración PDF
$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8', 
    'format' => [80, 200], 
    'margin_left' => 5, 
    'margin_right' => 5, 
    'margin_top' => 5, 
    'margin_bottom' => 5
]);

$html = '
<style>
    body { font-family: sans-serif; font-size: 11px; }
    .center { text-align: center; }
    .bold { font-weight: bold; }
    .line { border-bottom: 1px dashed #000; margin: 5px 0; }
    .total-row { font-size: 14px; font-weight: bold; text-align: right; border-top: 1px solid #000; margin-top: 5px; padding-top: 5px;}
    td { padding: 2px 0; }
</style>

<div class="center">
    <h3>JOYERÍA 2025</h3>
    <p class="bold">COMPROBANTE DE PAGO</p>
    <p>Ticket #: ' . str_pad($venta['id'], 6, "0", STR_PAD_LEFT) . '</p>
    <p>Fecha: ' . date('d/m/Y H:i', strtotime($venta['fecha'])) . '</p>
    <p>Atendió: ' . $venta['vendedor'] . '</p>
</div>

<div class="line"></div>

<div>
    <strong>Cliente:</strong> ' . $venta['nombre'] . ' ' . $venta['apellido'] . '<br>
    <strong>DNI:</strong> ' . $venta['dni'] . '
</div>

<div class="line"></div>

<table width="100%">
    <thead>
        <tr>
            <th align="left">Desc.</th>
            <th align="right">Monto</th>
        </tr>
    </thead>
    <tbody>';

foreach ($detalles as $row) {
    $html .= '
        <tr>
            <td>' . $row['descripcion_servicio'] . '</td>
            <td align="right">$' . number_format($row['subtotal'], 2) . '</td>
        </tr>';
}

$html .= '
    </tbody>
</table>

<div class="total-row">
    TOTAL PAGADO: $' . number_format($venta['total'], 2) . '
</div>

<div style="margin-top: 10px;">
    <strong>Método de Pago:</strong> ' . ($venta['metodo_pago'] ?? 'Efectivo') . '
</div>

<div class="center" style="margin-top:20px; font-size:10px;">
    <p>¡Gracias por su preferencia!</p>
</div>';

$mpdf->WriteHTML($html);
$mpdf->Output('Ticket_Pago_' . $id_venta . '.pdf', 'I');
?>