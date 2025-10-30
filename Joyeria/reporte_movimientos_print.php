
<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once "config/database.php";
$database = new Database();
$db = $database->getConnection();

// Obtener parámetros de filtro
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');
$tipo_movimiento = $_GET['tipo_movimiento'] ?? '';
$categoria = $_GET['categoria'] ?? '';

// Construir consulta para movimientos de stock
$query_movimientos = "SELECT m.*, p.nombre as producto_nombre, 
                             c.nombre as categoria_nombre,
                             u.nombre as usuario_nombre
                      FROM movimientos_stock m
                      INNER JOIN productos p ON m.id_producto = p.id
                      INNER JOIN categorias c ON p.id_categoria = c.id
                      INNER JOIN usuarios u ON m.id_usuario = u.id
                      WHERE m.fecha BETWEEN :fecha_inicio AND :fecha_fin";

$params = [
    ':fecha_inicio' => $fecha_inicio . ' 00:00:00',
    ':fecha_fin' => $fecha_fin . ' 23:59:59'
];

// Aplicar filtros adicionales
if (!empty($tipo_movimiento)) {
    $query_movimientos .= " AND m.tipo = :tipo_movimiento";
    $params[':tipo_movimiento'] = $tipo_movimiento;
}

if (!empty($categoria)) {
    $query_movimientos .= " AND p.id_categoria = :categoria";
    $params[':categoria'] = $categoria;
}

// Omitimos "AJUSTE" si es necesario (aunque al quitarlo del filtro, ya no debería llegar aquí)
if ($tipo_movimiento != 'AJUSTE') {
     $query_movimientos .= " AND m.tipo != 'AJUSTE'";
}


$query_movimientos .= " ORDER BY m.fecha ASC";

// Preparar y ejecutar consulta de movimientos
$stmt_movimientos = $db->prepare($query_movimientos);
foreach ($params as $key => $value) {
    $stmt_movimientos->bindValue($key, $value);
}
$stmt_movimientos->execute();
$movimientos = $stmt_movimientos->fetchAll(PDO::FETCH_ASSOC);

// Obtener nombre de categoría para el filtro
$categoria_nombre = 'Todas';
if (!empty($categoria)) {
    $stmt_cat = $db->prepare("SELECT nombre FROM categorias WHERE id = :id");
    $stmt_cat->execute([':id' => $categoria]);
    $cat = $stmt_cat->fetch(PDO::FETCH_ASSOC);
    if ($cat) {
        $categoria_nombre = $cat['nombre'];
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Movimientos de Stock</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        h1, h2 {
            text-align: center;
            border-bottom: 1px solid #ccc;
            padding-bottom: 10px;
        }
        .filtros {
            margin-bottom: 20px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 0.9em;
        }
        .filtros p {
            margin: 5px 0;
        }
        .filtros strong {
            display: inline-block;
            width: 150px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 0.8em;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f4f4f4;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        @media print {
            body {
                margin: 0;
            }
            .filtros {
                border: none;
            }
            button {
                display: none;
            }
        }
    </style>
</head>
<body onload="window.print()">
    <button onclick="window.print()" style="float: right; padding: 10px; margin-bottom: 10px;">Imprimir</button>
    <h1>Reporte de Movimientos de Stock</h1>

    <div class="filtros">
        <h2>Filtros Aplicados</h2>
        <p><strong>Rango de Fechas:</strong> <?php echo htmlspecialchars($fecha_inicio); ?> al <?php echo htmlspecialchars($fecha_fin); ?></p>
        <p><strong>Tipo de Movimiento:</strong> <?php echo empty($tipo_movimiento) ? 'Todos (Entrada/Salida)' : htmlspecialchars($tipo_movimiento); ?></p>
        <p><strong>Categoría:</strong> <?php echo htmlspecialchars($categoria_nombre); ?></p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Producto</th>
                <th>Categoría</th>
                <th>Tipo</th>
                <th>Cantidad</th>
                <th>Stock Anterior</th>
                <th>Stock Nuevo</th>
                <th>Motivo</th>
                <th>Usuario</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($movimientos) > 0): ?>
                <?php foreach ($movimientos as $movimiento): ?>
                    <tr>
                        <td><?php echo date('d/m/Y H:i', strtotime($movimiento['fecha'])); ?></td>
                        <td><?php echo htmlspecialchars($movimiento['producto_nombre']); ?></td>
                        <td><?php echo htmlspecialchars($movimiento['categoria_nombre']); ?></td>
                        <td><?php echo htmlspecialchars($movimiento['tipo']); ?></td>
                        <td><?php echo $movimiento['cantidad']; ?></td>
                        <td><?php echo $movimiento['cantidad_anterior']; ?></td>
                        <td><?php echo $movimiento['cantidad_nueva']; ?></td>
                        <td><?php echo htmlspecialchars($movimiento['motivo']); ?></td>
                        <td><?php echo htmlspecialchars($movimiento['usuario_nombre']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" style="text-align: center;">No se encontraron movimientos con los filtros aplicados.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

</body>
</html>