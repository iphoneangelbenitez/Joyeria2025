<?php
// generar_pdf.php
session_start();
require_once 'vendor/autoload.php';
require_once "config/database.php";

// 1. Validar sesión
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 2. Validar ID de venta
if (!isset($_GET['id_venta']) || empty($_GET['id_venta'])) {
    die("Error: No se especificó el ID de la venta.");
}

$idVenta = (int)$_GET['id_venta'];
$baseDeDatos = new Database();
$conexion = $baseDeDatos->getConnection();

// 3. Obtener datos de la Venta y Cliente
// CORRECCIONES SQL: 
// - Tabla 'usuarios' en lugar de 'users'
// - Se cambió 'c.direccion' (que no existe) por 'c.email'
$queryVenta = "SELECT v.*, c.nombre as cliente_nombre, c.apellido as cliente_apellido, c.dni as cliente_dni, 
                      c.email as cliente_email, c.telefono as cliente_telefono,
                      u.nombre as vendedor
               FROM ventas v 
               JOIN clientes c ON v.id_cliente = c.id 
               JOIN usuarios u ON v.id_usuario = u.id 
               WHERE v.id = :id";

$stmt = $conexion->prepare($queryVenta);
$stmt->bindParam(':id', $idVenta);
$stmt->execute();
$venta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$venta) {
    die("Error: Venta no encontrada.");
}

// 4. Obtener detalles de la venta (Productos)
$queryDetalles = "SELECT vd.*, p.nombre as producto_nombre 
                  FROM venta_detalles vd 
                  JOIN productos p ON vd.id_producto = p.id 
                  WHERE vd.id_venta = :id";
$stmtDetalles = $conexion->prepare($queryDetalles);
$stmtDetalles->bindParam(':id', $idVenta);
$stmtDetalles->execute();
$detalles = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);

// 5. Obtener configuración del negocio (Opcional, datos quemados por defecto)
// Si decides crear una tabla 'configuracion' en el futuro, esto funcionará.
$negocioNombre = 'Joyería Sosa';
$negocioDireccion = 'San Lorenzo 1869'; // Puedes editar esto manualmente
$negocioTelefono = '376442-5674';
$negocioEmail = 'joyeriasosa@gmail.com';

// --- INICIO GENERACIÓN PDF ---

// Crear instancia de mPDF
$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8', 
    'format' => 'A4', 
    'margin_left' => 15,
    'margin_right' => 15,
    'margin_top' => 15,
    'margin_bottom' => 15
]);

// Estilos CSS para el ticket
$css = '
<style>
    body { font-family: sans-serif; font-size: 10pt; color: #333; }
    .header-table { width: 100%; border-bottom: 2px solid #ddd; padding-bottom: 10px; margin-bottom: 20px; }
    .logo { width: 120px; }
    .empresa-info { text-align: right; }
    .empresa-nombre { font-size: 16pt; font-weight: bold; color: #000; }
    .titulo-doc { font-size: 14pt; font-weight: bold; text-align: center; margin-top: 10px; background-color: #f4f4f4; padding: 5px; border: 1px solid #ddd; }
    
    .info-cliente-table { width: 100%; margin-bottom: 20px; }
    .label { font-weight: bold; width: 100px; }
    
    .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    .items-table th { background-color: #333; color: #fff; padding: 8px; text-align: left; font-size: 9pt; }
    .items-table td { border-bottom: 1px solid #ddd; padding: 8px; font-size: 9pt; }
    .text-right { text-align: right; }
    .text-center { text-align: center; }
    
    .totales-table { width: 40%; margin-left: auto; border: 1px solid #ddd; }
    .totales-table td { padding: 5px; }
    .total-final { background-color: #f4f4f4; font-weight: bold; font-size: 11pt; }
    
    .footer { text-align: center; margin-top: 50px; font-size: 8pt; color: #777; border-top: 1px solid #ddd; padding-top: 10px; }
</style>
';

// Contenido HTML
$html = '
<table class="header-table">
    <tr>
        <td width="50%">
            <div class="empresa-nombre">' . htmlspecialchars($negocioNombre) . '</div>
            ' . htmlspecialchars($negocioDireccion) . '<br>
            Tel: ' . htmlspecialchars($negocioTelefono) . '<br>
            Email: ' . htmlspecialchars($negocioEmail) . '
        </td>
        <td width="50%" class="empresa-info">
            <b>Fecha:</b> ' . date("d/m/Y H:i", strtotime($venta['fecha'])) . '<br>
            <b>Nro. Venta:</b> #' . str_pad($venta['id'], 6, "0", STR_PAD_LEFT) . '<br>
            <b>Vendedor:</b> ' . htmlspecialchars($venta['vendedor']) . '
        </td>
    </tr>
</table>

<div class="titulo-doc">COMPROBANTE DE VENTA</div>

<table class="info-cliente-table">
    <tr>
        <td class="label">Cliente:</td>
        <td>' . htmlspecialchars($venta['cliente_apellido'] . ', ' . $venta['cliente_nombre']) . '</td>
        <td class="label">DNI:</td>
        <td>' . htmlspecialchars($venta['cliente_dni']) . '</td>
    </tr>
    <tr>
        <td class="label">Email:</td>
        <td>' . htmlspecialchars($venta['cliente_email'] ?? 'No registrado') . '</td>
        <td class="label">Teléfono:</td>
        <td>' . htmlspecialchars($venta['cliente_telefono'] ?? 'No registrado') . '</td>
    </tr>
    <tr>
        <td class="label">Pago:</td>
        <td>' . htmlspecialchars($venta['metodo_pago']) . '</td>
        <td></td>
        <td></td>
    </tr>
</table>

<table class="items-table">
    <thead>
        <tr>
            <th width="10%">Cant.</th>
            <th width="50%">Descripción del Producto</th>
            <th width="20%" class="text-right">Precio Unit.</th>
            <th width="20%" class="text-right">Subtotal</th>
        </tr>
    </thead>
    <tbody>';

foreach ($detalles as $item) {
    $precioUnit = (float)$item['precio_unitario'];
    $cantidad = (int)$item['cantidad'];
    $subtotalItem = $precioUnit * $cantidad;
    
    $html .= '
        <tr>
            <td class="text-center">' . $cantidad . '</td>
            <td>' . htmlspecialchars($item['producto_nombre']) . '</td>
            <td class="text-right">$' . number_format($precioUnit, 2) . '</td>
            <td class="text-right">$' . number_format($subtotalItem, 2) . '</td>
        </tr>';
}

$html .= '
    </tbody>
</table>

<table class="totales-table">
    <tr>
        <td class="text-right">Subtotal:</td>
        <td class="text-right">$' . number_format($venta['subtotal'], 2) . '</td>
    </tr>';

if ($venta['descuento'] > 0) {
    $montoDescuento = $venta['subtotal'] - $venta['total'];
    $html .= '
    <tr>
        <td class="text-right">Descuento (' . floatval($venta['descuento']) . '%):</td>
        <td class="text-right">- $' . number_format($montoDescuento, 2) . '</td>
    </tr>';
}

$html .= '
    <tr class="total-final">
        <td class="text-right">TOTAL A PAGAR:</td>
        <td class="text-right">$' . number_format($venta['total'], 2) . '</td>
    </tr>
</table>

<div class="footer">
    Gracias por su compra.<br>
    Documento no válido como factura fiscal si no se adjunta ticket oficial.
</div>
';

// Escribir PDF
$mpdf->WriteHTML($css . $html);

// Salida directa al navegador
$mpdf->Output('Ticket_Venta_#' . $idVenta . '.pdf', 'I');
?>