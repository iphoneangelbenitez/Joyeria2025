<?php
/* --- 1. CONFIGURACIÓN DE ERRORES --- */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

/* Compatibilidad de sesión */
if (!isset($_SESSION['id_usuario']) && isset($_SESSION['user_id'])) {
    $_SESSION['id_usuario'] = $_SESSION['user_id'];
}

/* --- 2. CONEXIÓN --- */
require_once "config/database.php";
$baseDeDatos = new Database();
$conexion = $baseDeDatos->getConnection();

$error = '';
$mensaje = '';

// --- 3. VALIDAR Y OBTENER SERVICIO ---
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: servicios.php");
    exit;
}

$id_servicio = (int)$_GET['id'];

try {
    $sql = "SELECT s.*, 
                   c.nombre as nombre_cliente, 
                   c.apellido as apellido_cliente, 
                   c.dni as dni_cliente, 
                   c.id as id_cliente 
            FROM servicios s 
            LEFT JOIN clientes c ON s.id_cliente = c.id 
            WHERE s.id = :id";
            
    $stmt = $conexion->prepare($sql);
    $stmt->bindParam(':id', $id_servicio, PDO::PARAM_INT);
    $stmt->execute();
    $servicio = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$servicio) {
        die("Error: El servicio no existe.");
    }

    // Calcular montos
    // Como no tienes columna 'saldo', asumimos que se debe pagar el costo total del servicio.
    $monto_a_pagar = $servicio['costo_servicio'];

} catch (PDOException $e) {
    die("Error BD al leer: " . $e->getMessage());
}

// --- 4. PROCESAR EL COBRO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_usuario = $_SESSION['id_usuario']; 
    $total = $monto_a_pagar; 
    $nuevo_estado = $_POST['estado_final']; 

    try {
        $conexion->beginTransaction();

        // A. Insertar Cabecera (servicios_ventas)
        $sqlVenta = "INSERT INTO servicios_ventas (fecha, id_cliente, total, id_usuario) VALUES (NOW(), :id_cliente, :total, :id_usuario)";
        $stmtV = $conexion->prepare($sqlVenta);
        $stmtV->bindParam(':id_cliente', $servicio['id_cliente'], PDO::PARAM_INT);
        $stmtV->bindParam(':total', $total);
        $stmtV->bindParam(':id_usuario', $id_usuario, PDO::PARAM_INT);
        $stmtV->execute();
        $id_venta_servicio = $conexion->lastInsertId();

        // B. Insertar Detalle (detalle_servicios_ventas)
        $sqlDetalle = "INSERT INTO detalle_servicios_ventas (id_venta_servicio, id_servicio, descripcion_servicio, precio, subtotal) VALUES (:id_venta, :id_servicio, :desc, :precio, :subtotal)";
        $desc = "Servicio #" . $servicio['id'] . " - " . $servicio['producto'];
        
        $stmtD = $conexion->prepare($sqlDetalle);
        $stmtD->bindParam(':id_venta', $id_venta_servicio, PDO::PARAM_INT);
        $stmtD->bindParam(':id_servicio', $id_servicio, PDO::PARAM_INT);
        $stmtD->bindParam(':desc', $desc);
        $stmtD->bindParam(':precio', $total);
        $stmtD->bindParam(':subtotal', $total);
        $stmtD->execute();

        // C. Actualizar Estado del Servicio (CORREGIDO: SIN CAMPO SALDO)
        if ($nuevo_estado === 'Pagado') {
             // Solo paga, no se entrega (no actualizamos fecha_entrega)
             $sqlUpdate = "UPDATE servicios SET estado = :estado WHERE id = :id";
        } else {
             // Paga y se entrega (actualizamos fechas)
             $sqlUpdate = "UPDATE servicios SET estado = :estado, fecha_completado = NOW(), fecha_entrega = NOW() WHERE id = :id";
        }

        $stmtU = $conexion->prepare($sqlUpdate);
        $stmtU->bindParam(':estado', $nuevo_estado); 
        $stmtU->bindParam(':id', $id_servicio, PDO::PARAM_INT);
        $stmtU->execute();

        $conexion->commit();
        
        echo "<script>
                alert('¡Pago registrado con éxito! Ticket #$id_venta_servicio');
                window.location.href = 'servicios.php';
              </script>";
        exit;

    } catch (PDOException $e) {
        $conexion->rollBack();
        $error = "Error al procesar: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Cobrar Servicio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="assets/css/theme-oscuro.css" />
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'light') document.documentElement.classList.add('theme-light');
        })();
    </script>
    <style>
        .card-cobro {
            max-width: 500px;
            margin: 50px auto;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.2);
        }
        .monto-big { font-size: 2.5rem; font-weight: 800; color: #2ecc71; text-align: center; margin: 20px 0; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #444; }
    </style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>

<div class="container">
    <?php if ($error): ?>
        <div class="alert alert-danger mt-3"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card card-cobro">
        <div class="card-header bg-success text-white text-center">
            <h4 class="mb-0">Cobrar Servicio #<?php echo $servicio['id']; ?></h4>
        </div>
        <div class="card-body p-4">
            
            <div class="text-center mb-3">
                <h5><?php echo htmlspecialchars($servicio['nombre_cliente'] . ' ' . $servicio['apellido_cliente']); ?></h5>
                <small class="text-muted">DNI: <?php echo htmlspecialchars($servicio['dni_cliente']); ?></small>
            </div>

            <div class="info-row">
                <span>Joya/Producto:</span>
                <strong><?php echo htmlspecialchars($servicio['producto']); ?></strong>
            </div>
            <div class="info-row">
                <span>Trabajo:</span>
                <span><?php echo htmlspecialchars($servicio['descripcion']); ?></span>
            </div>
            
            <div class="monto-big">
                $ <?php echo number_format($monto_a_pagar, 2); ?>
            </div>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Acción:</label>
                    <select name="estado_final" class="form-select">
                        <option value="Pagado y Entregado">Cobrar y Entregar (Finalizar)</option>
                        <option value="Pagado">Solo Cobrar (Queda en taller)</option>
                    </select>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-success btn-lg">CONFIRMAR PAGO</button>
                    <a href="servicios.php" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/boton-oscuro.js"></script>
</body>
</html>