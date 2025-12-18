<?php
// obtener_detalle_compra.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

require_once "config/database.php";

if (!isset($_GET['id_compra'])) {
    echo json_encode(['success' => false, 'message' => 'ID faltante']);
    exit();
}

$idCompra = (int)$_GET['id_compra'];
$database = new Database();
$db = $database->getConnection();

try {
    // 1. Cabecera de la compra
    $sqlCompra = "SELECT c.*, 
                         p.nombre as proveedor_nombre, p.empresa as proveedor_empresa, 
                         p.telefono as proveedor_telefono, p.email as proveedor_email,
                         u.nombre as usuario_nombre
                  FROM compras c
                  LEFT JOIN proveedores p ON c.id_proveedor = p.id
                  LEFT JOIN usuarios u ON c.id_usuario = u.id
                  WHERE c.id = :id";
    $stmt = $db->prepare($sqlCompra);
    $stmt->bindValue(':id', $idCompra);
    $stmt->execute();
    $compra = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$compra) {
        echo json_encode(['success' => false, 'message' => 'Compra no encontrada']);
        exit();
    }

    // 2. Detalles (Productos)
    $sqlDetalles = "SELECT cd.*, p.nombre as producto_nombre, cat.nombre as categoria_nombre
                    FROM compra_detalles cd
                    LEFT JOIN productos p ON cd.id_producto = p.id
                    LEFT JOIN categorias cat ON p.id_categoria = cat.id
                    WHERE cd.id_compra = :id";
    $stmtDet = $db->prepare($sqlDetalles);
    $stmtDet->bindValue(':id', $idCompra);
    $stmtDet->execute();
    $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

    // 3. Movimientos (Opcional, busca por fecha y usuario similar para mostrar trazabilidad)
    $sqlMov = "SELECT ms.*, p.nombre as producto_nombre 
               FROM movimientos_stock ms
               INNER JOIN productos p ON ms.id_producto = p.id
               WHERE ms.id_usuario = :user 
               AND ms.tipo = 'ENTRADA'
               AND ABS(TIMESTAMPDIFF(SECOND, ms.fecha, :fecha)) < 60"; // Busca movimientos creados en el mismo minuto
    
    // Simplificación: A veces vincular movimiento exacto es difícil sin un ID de transacción común.
    // Si esto falla, enviamos array vacío.
    $movimientos = [];
    
    echo json_encode([
        'success' => true,
        'compra' => $compra,
        'detalles' => $detalles,
        'movimientos' => $movimientos
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>