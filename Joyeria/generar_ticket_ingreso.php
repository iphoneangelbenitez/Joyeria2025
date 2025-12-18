<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once "config/database.php";

if (!isset($_GET['id'])) {
    die("Error: Falta el ID del servicio.");
}

$id_servicio = (int)$_GET['id'];
$baseDeDatos = new Database();
$conexion = $baseDeDatos->getConnection();

// Obtener datos del servicio y cliente
$sql = "SELECT s.*, c.nombre, c.apellido, c.dni, c.telefono 
        FROM servicios s 
        INNER JOIN clientes c ON s.id_cliente = c.id 
        WHERE s.id = :id";

$stmt = $conexion->prepare($sql);
$stmt->bindParam(':id', $id_servicio);
$stmt->execute();
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    die("Servicio no encontrado.");
}

// Configuración del PDF (Tamaño Ticket 80mm)
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
    .box { border: 1px solid #000; padding: 5px; margin-top: 5px; }
    .small { font-size: 9px; }
</style>

<div class="center">
    <h3>JOYERÍA SOSA</h3>
    <p class="bold">COMPROBANTE DE RECEPCIÓN</p>
    <p>Orden #: ' . str_pad($data['id'], 6, "0", STR_PAD_LEFT) . '</p>
    <p>Fecha: ' . date('d/m/Y H:i', strtotime($data['fecha_ingreso'])) . '</p>
</div>

<div class="line"></div>

<div>
    <strong>Cliente:</strong> ' . $data['nombre'] . ' ' . $data['apellido'] . '<br>
    <strong>DNI:</strong> ' . $data['dni'] . '<br>
    <strong>Tel:</strong> ' . $data['telefono'] . '
</div>

<div class="line"></div>

<div class="box">
    <strong>PRODUCTO:</strong><br>
    ' . $data['producto'] . '<br><br>
    <strong>TRABAJO A REALIZAR:</strong><br>
    ' . $data['descripcion'] . '
</div>

<br>
<div>
    <strong>Tipo:</strong> ' . $data['tipo'] . '<br>
    <strong>Entrega Estimada:</strong> ' . date('d/m/Y', strtotime($data['fecha_entrega_estimada'])) . '<br>
    <strong>Costo Estimado:</strong> $' . number_format($data['costo_servicio'], 2) . '
</div>

<div class="line"></div>

<div class="center small">
    <p>CONDICIONES:<br>
    1. Presentar este comprobante para el retiro.<br>
    2. Pasados 90 días no nos responsabilizamos por trabajos no retirados.<br>
    3. Garantía sobre mano de obra: 30 días.</p>
    
    <br><br>
    _________________________<br>
    Firma Cliente
</div>';

$mpdf->WriteHTML($html);
$mpdf->Output('Orden_Servicio_' . $id_servicio . '.pdf', 'I');
?>