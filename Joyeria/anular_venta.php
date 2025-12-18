<?php
session_start();
require_once "config/database.php";

// 1. Seguridad y Validación
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Solo permitimos a Administradores anular ventas (Opcional, puedes quitar esto)
if ($_SESSION['user_type'] !== 'ADM') {
    die("Error: No tienes permisos para anular ventas.");
}

if (!isset($_POST['id_venta']) || empty($_POST['motivo'])) {
    die("Error: Datos incompletos.");
}

$id_venta = (int)$_POST['id_venta'];
$motivo = trim($_POST['motivo']);
$id_usuario = $_SESSION['user_id'];

$db = new Database();
$con = $db->getConnection();

try {
    $con->beginTransaction();

    // 2. Verificar que la venta exista y no esté ya anulada
    $sqlCheck = "SELECT estado, total FROM ventas WHERE id = :id FOR UPDATE";
    $stmtCheck = $con->prepare($sqlCheck);
    $stmtCheck->bindParam(':id', $id_venta);
    $stmtCheck->execute();
    $venta = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        throw new Exception("Venta no encontrada.");
    }
    if ($venta['estado'] === 'ANULADA') {
        throw new Exception("Esta venta ya fue anulada anteriormente.");
    }

    // 3. Obtener los productos vendidos para devolverlos al stock
    $sqlDetalles = "SELECT id_producto, cantidad FROM venta_detalles WHERE id_venta = :id_venta";
    $stmtDetalles = $con->prepare($sqlDetalles);
    $stmtDetalles->bindParam(':id_venta', $id_venta);
    $stmtDetalles->execute();
    $productos = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);

    // 4. Restaurar Stock (Loop)
    $sqlUpdateStock = "UPDATE productos SET stock = stock + :cantidad WHERE id = :id_producto";
    $stmtUpdateStock = $con->prepare($sqlUpdateStock);

    foreach ($productos as $prod) {
        $stmtUpdateStock->bindParam(':cantidad', $prod['cantidad']);
        $stmtUpdateStock->bindParam(':id_producto', $prod['id_producto']);
        $stmtUpdateStock->execute();
    }

    // 5. Cambiar estado de la venta a ANULADA
    $sqlEstado = "UPDATE ventas SET estado = 'ANULADA' WHERE id = :id";
    $stmtEstado = $con->prepare($sqlEstado);
    $stmtEstado->bindParam(':id', $id_venta);
    $stmtEstado->execute();

    // 6. Generar la Nota de Crédito (Registro)
    $sqlNota = "INSERT INTO notas_credito (id_venta, fecha, motivo, total, id_usuario) 
                VALUES (:id_venta, NOW(), :motivo, :total, :id_usuario)";
    $stmtNota = $con->prepare($sqlNota);
    $stmtNota->bindParam(':id_venta', $id_venta);
    $stmtNota->bindParam(':motivo', $motivo);
    $stmtNota->bindParam(':total', $venta['total']);
    $stmtNota->bindParam(':id_usuario', $id_usuario);
    $stmtNota->execute();
    
    $id_nota = $con->lastInsertId();

    $con->commit();

    // 7. Redirigir o Imprimir
    // Podrías redirigir a un generador de PDF para la nota de crédito
    echo "<script>
        alert('Venta anulada correctamente. Stock restaurado.');
        window.location.href = 'reportes_ventas.php';
    </script>";

} catch (Exception $e) {
    $con->rollBack();
    echo "<script>
        alert('Error al anular: " . $e->getMessage() . "');
        window.history.back();
    </script>";
}
?>