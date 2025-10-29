<?php
// generar_pdf.php
// Un solo archivo con 2 modos:
// - action=view (default): muestra iframe con PDF + cuenta regresiva para volver
// - action=pdf: genera y entrega el PDF del ticket

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// -------------------------
// Parámetros comunes
// -------------------------
$idVenta = isset($_GET['id_venta']) ? (int)$_GET['id_venta'] : 0;
if ($idVenta <= 0) {
    http_response_code(400);
    echo "Parámetro 'id_venta' inválido.";
    exit();
}

$action = isset($_GET['action']) ? strtolower($_GET['action']) : 'view';

// Para la vista con cuenta regresiva:
$segundos = isset($_GET['t']) ? (int)$_GET['t'] : 7;
if ($segundos < 3) $segundos = 3;
if ($segundos > 60) $segundos = 60;

$back = isset($_GET['back']) ? $_GET['back'] : 'ventas.php';
// No permitir URLs absolutas externas
if (preg_match('#^https?://#i', $back)) {
    $back = 'ventas.php';
}

// -------------------------
// Modo VIEW (HTML + iframe + countdown)
// -------------------------
if ($action !== 'pdf') {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
    <meta charset="utf-8">
    <title>Ticket de Venta #<?= htmlspecialchars($idVenta) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        *{box-sizing:border-box}
        body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#111;color:#eee}
        header{padding:10px 14px;border-bottom:1px solid #222;display:flex;align-items:center;gap:10px}
        header .title{font-weight:600}
        header .spacer{flex:1}
        header .pill{
            font-size:14px;background:#1f2937;border:1px solid #374151;border-radius:999px;
            padding:6px 10px;display:inline-flex;align-items:center;gap:6px
        }
        header .btn{
            appearance:none;border:1px solid #374151;background:#111;color:#eee;border-radius:10px;
            padding:8px 12px;cursor:pointer;font-weight:600
        }
        header .btn:hover{background:#0b0b0b}
        .wrap{height:calc(100vh - 56px);display:grid;grid-template-rows:1fr}
        iframe{width:100%;height:100%;border:0;background:#222}
    </style>
    </head>
    <body>
    <header>
        <div class="title">Ticket de Venta #<?= htmlspecialchars($idVenta) ?></div>
        <div class="spacer"></div>
        <div class="pill">Regresando en <span id="counter"><?= (int)$segundos ?></span>s</div>
        <button class="btn" onclick="window.location.href='<?= htmlspecialchars($back, ENT_QUOTES) ?>'">Volver ahora</button>
    </header>
    <div class="wrap">
        <iframe
            src="generar_pdf.php?action=pdf&id_venta=<?= (int)$idVenta ?>#toolbar=0"
            title="Ticket PDF"></iframe>
    </div>

    <script>
    (function(){
        var s = <?= (int)$segundos ?>;
        var counter = document.getElementById('counter');
        var backUrl = '<?= htmlspecialchars($back, ENT_QUOTES) ?>';
        var timer = setInterval(function(){
            s--;
            if (s <= 0) {
                clearInterval(timer);
                window.location.href = backUrl;
            } else {
                counter.textContent = s;
            }
        }, 1000);
    })();
    </script>
    </body>
    </html>
    <?php
    exit();
}

// -------------------------
// Modo PDF (genera el ticket)
// -------------------------

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . '/vendor/autoload.php'; // mPDF + mpdf/qrcode

// DB
$database = new Database();
$db = $database->getConnection();

// Utilidad
function money_ar($n) { return '$ ' . number_format((float)$n, 2, ',', '.'); }

// Cabecera venta (esquema real)
$sqlVenta = "
    SELECT 
        v.id,
        v.id_cliente,
        v.id_usuario,
        v.fecha,
        v.descuento,
        v.subtotal,
        v.total,
        v.metodo_pago
    FROM ventas v
    WHERE v.id = :id
    LIMIT 1
";
$stmt = $db->prepare($sqlVenta);
$stmt->bindValue(':id', $idVenta, PDO::PARAM_INT);
$stmt->execute();
$venta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$venta) {
    http_response_code(404);
    echo "La venta #{$idVenta} no existe.";
    exit();
}

// Cliente
$cliente = [
    'nombre'   => 'Consumidor',
    'apellido' => 'Final',
    'dni'      => '',
    'telefono' => '',
    'email'    => ''
];

$sqlCliente = "SELECT nombre, apellido, dni, telefono, email FROM clientes WHERE id = :id LIMIT 1";
$sc = $db->prepare($sqlCliente);
$sc->bindValue(':id', (int)$venta['id_cliente'], PDO::PARAM_INT);
$sc->execute();
if ($c = $sc->fetch(PDO::FETCH_ASSOC)) $cliente = array_merge($cliente, $c);

// Vendedor
$usuario = [
    'nombre' => isset($_SESSION['user_name']) ? $_SESSION['user_name'] : (isset($_SESSION['username']) ? $_SESSION['username'] : 'Vendedor')
];
$sqlUser = "SELECT nombre, username FROM usuarios WHERE id = :id LIMIT 1";
$su = $db->prepare($sqlUser);
$su->bindValue(':id', (int)$venta['id_usuario'], PDO::PARAM_INT);
$su->execute();
if ($u = $su->fetch(PDO::FETCH_ASSOC)) {
    $usuario['nombre'] = !empty($u['nombre']) ? $u['nombre'] : (!empty($u['username']) ? $u['username'] : $usuario['nombre']);
}

// Ítems
$sqlItems = "
    SELECT 
        d.id_producto,
        d.cantidad,
        d.precio_unitario,
        (d.cantidad * d.precio_unitario) AS subtotal,
        p.nombre AS producto_nombre
    FROM venta_detalles d
    LEFT JOIN productos p ON p.id = d.id_producto
    WHERE d.id_venta = :id
    ORDER BY d.id ASC
";
$si = $db->prepare($sqlItems);
$si->bindValue(':id', $idVenta, PDO::PARAM_INT);
$si->execute();
$items = $si->fetchAll(PDO::FETCH_ASSOC);

// Cálculos
$subtotal = (float)$venta['subtotal'];
$descuento = (float)$venta['descuento'];
$total = (float)$venta['total'];
$fechaVenta = !empty($venta['fecha']) ? date('d/m/Y H:i', strtotime($venta['fecha'])) : date('d/m/Y H:i');

// Datos comercio
$tienda = [
    'nombre'    => 'JOYERÍA SOSA',
    'cuit'      => 'CUIT: 20-12345678-9',
    'direccion' => 'San Lorenzo 1869 - Posadas',
    'telefono'  => '351-555-1234',
    'pie'       => 'Gracias por su compra',
];
$logoPath = __DIR__ . '/assets/img/logo.png';
$logoExists = is_file($logoPath);

// Estilos
$css = '
*{ box-sizing:border-box; }
body{ font-family: DejaVu Sans, Arial, sans-serif; font-size:10pt; margin:0; }
.ticket{ width:100%; padding:6px 6px 10px; }
.header{ text-align:center; }
.header .brand{ font-size:12pt; font-weight:bold; margin-top:4px; }
.header .meta{ font-size:9pt; line-height:1.2; }
hr{ border:none; border-top:1px dashed #000; margin:6px 0; }
.table{ width:100%; border-collapse:collapse; }
.th, .td{ font-size:9pt; padding:2px 0; }
.right{ text-align:right; }
.center{ text-align:center; }
.small{ font-size:8pt; }
.mono{ font-family: DejaVu Sans Mono, monospace; }
.item-row{ vertical-align:top; }
.totales .td{ font-size:10pt; }
.bold{ font-weight:bold; }
.qr{ margin-top:6px; text-align:center; }
footer{ text-align:center; margin-top:8px; font-size:9pt; }
';

// Código QR
$ticketCode = 'TKT-' . str_pad((string)$venta['id'], 6, '0', STR_PAD_LEFT);

// HTML del ticket
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Ticket #<?= htmlspecialchars($venta['id']); ?></title>
    <style><?= $css; ?></style>
</head>
<body>
<div class="ticket">
    <div class="header">
        <?php if ($logoExists): ?>
            <img src="<?= htmlspecialchars($logoPath); ?>" style="max-width:120px; max-height:60px;" />
        <?php endif; ?>
        <div class="brand"><?= htmlspecialchars($tienda['nombre']); ?></div>
        <div class="meta">
            <?= htmlspecialchars($tienda['cuit']); ?><br>
            <?= htmlspecialchars($tienda['direccion']); ?><br>
            Tel: <?= htmlspecialchars($tienda['telefono']); ?>
        </div>
    </div>

    <hr>

    <table class="table">
        <tr>
            <td class="td small">Ticket:</td>
            <td class="td small right mono">#<?= htmlspecialchars($venta['id']); ?></td>
        </tr>
        <tr>
            <td class="td small">Fecha:</td>
            <td class="td small right mono"><?= htmlspecialchars($fechaVenta); ?></td>
        </tr>
        <tr>
            <td class="td small">Vendedor:</td>
            <td class="td small right"><?= htmlspecialchars($usuario['nombre']); ?></td>
        </tr>
        <?php
        $nombreCliente = trim(($cliente['apellido'] ?? '').' '.($cliente['nombre'] ?? ''));
        $nombreCliente = trim($nombreCliente) !== '' ? $nombreCliente : 'Consumidor Final';
        ?>
        <tr>
            <td class="td small">Cliente:</td>
            <td class="td small right"><?= htmlspecialchars($nombreCliente); ?></td>
        </tr>
        <?php if (!empty($cliente['dni'])): ?>
        <tr>
            <td class="td small">DNI:</td>
            <td class="td small right mono"><?= htmlspecialchars($cliente['dni']); ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <td class="td small">Pago:</td>
            <td class="td small right"><?= htmlspecialchars($venta['metodo_pago']); ?></td>
        </tr>
    </table>

    <hr>

    <table class="table">
        <tr class="th mono">
            <td class="td">Descripción</td>
            <td class="td right">Cant</td>
            <td class="td right">P.Unit</td>
            <td class="td right">Subt</td>
        </tr>
        <?php if ($items): ?>
            <?php foreach ($items as $it): ?>
            <tr class="item-row">
                <td class="td mono"><?= htmlspecialchars($it['producto_nombre'] ?: 'Producto #'.$it['id_producto']); ?></td>
                <td class="td right mono"><?= (int)$it['cantidad']; ?></td>
                <td class="td right mono"><?= money_ar($it['precio_unitario']); ?></td>
                <td class="td right mono"><?= money_ar($it['subtotal']); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td class="td small" colspan="4">Sin ítems</td></tr>
        <?php endif; ?>
    </table>

    <hr>

    <table class="table totales">
        <tr>
            <td class="td right mono" colspan="3">Subtotal</td>
            <td class="td right mono"><?= money_ar($subtotal); ?></td>
        </tr>
        <?php if ($descuento > 0): ?>
        <tr>
            <td class="td right mono" colspan="3">Descuento</td>
            <td class="td right mono">- <?= money_ar($descuento); ?></td>
        </tr>
        <?php endif; ?>
        <tr class="bold">
            <td class="td right mono" colspan="3">TOTAL</td>
            <td class="td right mono"><?= money_ar($total); ?></td>
        </tr>
    </table>

    <div class="qr">
        <barcode code="<?= htmlspecialchars($ticketCode); ?>" type="QR" size="1.1" error="M" />
        <div class="small mono"><?= htmlspecialchars($ticketCode); ?></div>
    </div>

    <hr>

    <footer>
        <?= htmlspecialchars($tienda['pie']); ?><br>
        <span class="small">No válido como factura</span>
    </footer>
</div>
</body>
</html>
<?php
$html = ob_get_clean();

try {
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => [80, 200],  // Cambia a [58, 200] si tu impresora es 58mm
        'margin_left' => 2,
        'margin_right' => 2,
        'margin_top' => 2,
        'margin_bottom' => 2,
        'margin_header' => 0,
        'margin_footer' => 0,
        'default_font_size' => 10,
        'default_font' => 'dejavusans',
        'autoScriptToLang' => true,
        'autoLangToFont' => true,
        'shrink_tables_to_fit' => 1
    ]);
    $mpdf->showImageErrors = true;
    $mpdf->WriteHTML($html);
    $nombreArchivo = "ticket_venta_{$venta['id']}.pdf";
    $mpdf->Output($nombreArchivo, \Mpdf\Output\Destination::INLINE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo "Error generando PDF: " . htmlspecialchars($e->getMessage());
    exit();
}
