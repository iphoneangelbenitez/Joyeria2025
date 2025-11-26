<?php
// obtener_detalle_compra.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

require_once "config/database.php";

// Verificar que se haya proporcionado el ID de la compra
if (!isset($_GET['id_compra']) || empty($_GET['id_compra'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'ID de compra no proporcionado']);
    exit();
}

$id_compra = $_GET['id_compra'];

try {
    $database = new Database();
    $db = $database->getConnection();

    // Consulta para obtener los datos generales de la compra
    $query_compra = "SELECT 
                        c.*,
                        p.empresa as proveedor_nombre,
                        p.nombre as proveedor_contacto_nombre,
                        p.apellido as proveedor_contacto_apellido,
                        p.telefono as proveedor_telefono,
                        p.email as proveedor_email,
                        u.nombre as usuario_nombre
                     FROM compras c
                     INNER JOIN proveedores p ON c.id_proveedor = p.id
                     INNER JOIN usuarios u ON c.id_usuario = u.id
                     WHERE c.id = :id_compra";

    $stmt_compra = $db->prepare($query_compra);
    $stmt_compra->bindValue(':id_compra', $id_compra, PDO::PARAM_INT);
    $stmt_compra->execute();
    $compra = $stmt_compra->fetch(PDO::FETCH_ASSOC);

    if (!$compra) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Compra no encontrada']);
        exit();
    }

    // Consulta para obtener los detalles de la compra
    $query_detalles = "SELECT 
                          cd.*,
                          pr.nombre as producto_nombre,
                          pr.descripcion as producto_descripcion,
                          cat.nombre as categoria_nombre
                       FROM compra_detalles cd
                       INNER JOIN productos pr ON cd.id_producto = pr.id
                       INNER JOIN categorias cat ON pr.id_categoria = cat.id
                       WHERE cd.id_compra = :id_compra
                       ORDER BY cd.id";

    $stmt_detalles = $db->prepare($query_detalles);
    $stmt_detalles->bindValue(':id_compra', $id_compra, PDO::PARAM_INT);
    $stmt_detalles->execute();
    $detalles = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);

    // Consulta para obtener movimientos de stock relacionados con esta compra
    $query_movimientos = "SELECT 
                             ms.*,
                             pr.nombre as producto_nombre
                          FROM movimientos_stock ms
                          INNER JOIN productos pr ON ms.id_producto = pr.id
                          WHERE ms.motivo LIKE :motivo_compra
                          AND ms.fecha >= :fecha_compra
                          ORDER BY ms.fecha DESC";

    $stmt_movimientos = $db->prepare($query_movimientos);
    $stmt_movimientos->bindValue(':motivo_compra', "%Compra #" . $id_compra . "%");
    $stmt_movimientos->bindValue(':fecha_compra', $compra['fecha_registro']);
    $stmt_movimientos->execute();
    $movimientos = $stmt_movimientos->fetchAll(PDO::FETCH_ASSOC);

    // Formatear los datos para la respuesta
    $response = [
        'success' => true,
        'compra' => [
            'id' => $compra['id'],
            'fecha_compra' => $compra['fecha_compra'],
            'fecha_registro' => $compra['fecha_registro'],
            'proveedor_nombre' => $compra['proveedor_nombre'],
            'proveedor_contacto' => $compra['proveedor_contacto_nombre'] . ' ' . $compra['proveedor_contacto_apellido'],
            'proveedor_telefono' => $compra['proveedor_telefono'],
            'proveedor_email' => $compra['proveedor_email'],
            'usuario_nombre' => $compra['usuario_nombre'],
            'forma_pago' => $compra['forma_pago'],
            'total' => $compra['total'],
            'observaciones' => $compra['observaciones'],
            'imagen_factura' => $compra['imagen_factura']
        ],
        'detalles' => [],
        'movimientos' => []
    ];

    // Procesar detalles
    foreach ($detalles as $detalle) {
        $response['detalles'][] = [
            'id' => $detalle['id'],
            'producto_nombre' => $detalle['producto_nombre'],
            'producto_descripcion' => $detalle['producto_descripcion'],
            'categoria_nombre' => $detalle['categoria_nombre'],
            'cantidad' => $detalle['cantidad'],
            'costo_unitario' => $detalle['costo_unitario'],
            'subtotal' => $detalle['subtotal']
        ];
    }

    // Procesar movimientos de stock
    foreach ($movimientos as $movimiento) {
        $response['movimientos'][] = [
            'id' => $movimiento['id'],
            'producto_nombre' => $movimiento['producto_nombre'],
            'tipo' => $movimiento['tipo'],
            'cantidad' => $movimiento['cantidad'],
            'cantidad_anterior' => $movimiento['cantidad_anterior'],
            'cantidad_nueva' => $movimiento['cantidad_nueva'],
            'motivo' => $movimiento['motivo'],
            'fecha' => $movimiento['fecha']
        ];
    }

    header('Content-Type: application/json');
    echo json_encode($response);

} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Error de base de datos: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>