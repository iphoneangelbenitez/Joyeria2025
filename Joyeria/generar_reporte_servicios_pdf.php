<?php
// generar_reporte_servicios_pdf.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once "config/database.php";
$database = new Database();
$db = $database->getConnection();

// 1. Obtener parámetros de filtro (igual que en reportes_servicios.php)
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');
$tipo_servicio = $_GET['tipo_servicio'] ?? '';
$estado_servicio = $_GET['estado_servicio'] ?? '';
$dni_cliente = $_GET['dni_cliente'] ?? '';

// 2. Construir y ejecutar la consulta (exactamente la misma que para "Detalle de Servicios")
$query_servicios = "SELECT s.*, c.nombre as cliente_nombre, c.apellido as cliente_apellido, 
                           c.dni as cliente_dni, c.telefono as cliente_telefono,
                           DATEDIFF(s.fecha_entrega_estimada, s.fecha_ingreso) as dias_estimados,
                           DATEDIFF(COALESCE(s.fecha_completado, NOW()), s.fecha_ingreso) as dias_reales
                    FROM servicios s
                    INNER JOIN clientes c ON s.id_cliente = c.id
                    WHERE s.fecha_ingreso BETWEEN :fecha_inicio AND :fecha_fin";

$params = [
    ':fecha_inicio' => $fecha_inicio . ' 00:00:00',
    ':fecha_fin' => $fecha_fin . ' 23:59:59'
];

// Aplicar filtros adicionales
if (!empty($tipo_servicio)) {
    $query_servicios .= " AND s.tipo = :tipo_servicio";
    $params[':tipo_servicio'] = $tipo_servicio;
}

if (!empty($estado_servicio)) {
    $query_servicios .= " AND s.estado = :estado_servicio";
    $params[':estado_servicio'] = $estado_servicio;
}

if (!empty($dni_cliente)) {
    $query_servicios .= " AND c.dni LIKE :dni_cliente";
    $params[':dni_cliente'] = "%$dni_cliente%";
}

$query_servicios .= " ORDER BY s.fecha_ingreso DESC";

// Preparar y ejecutar consulta de servicios
$stmt_servicios = $db->prepare($query_servicios);
foreach ($params as $key => $value) {
    $stmt_servicios->bindValue($key, $value);
}
$stmt_servicios->execute();
$servicios = $stmt_servicios->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte Detallado de Servicios</title>
    <style>
        /* ESTILOS COPIADOS DE reporte_movimientos_print.php */
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
            font-size: 0.8em; /* Ajustado para que quepan más columnas */
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
            table {
                font-size: 0.75em; /* Aún más pequeño para impresión si es necesario */
            }
        }
    </style>
</head>
<body onload="window.print()"> <button onclick="window.print()" style="float: right; padding: 10px; margin-bottom: 10px;">Imprimir</button>
    
    <h1>Reporte Detallado de Servicios</h1>

    <div class="filtros">
        <h2>Filtros Aplicados</h2>
        <p><strong>Rango de Fechas:</strong> <?php echo htmlspecialchars(date('d/m/Y', strtotime($fecha_inicio))); ?> al <?php echo htmlspecialchars(date('d/m/Y', strtotime($fecha_fin))); ?></p>
        <p><strong>Tipo de Servicio:</strong> <?php echo !empty($tipo_servicio) ? htmlspecialchars($tipo_servicio) : 'Todos'; ?></p>
        <p><strong>Estado:</strong> <?php echo !empty($estado_servicio) ? htmlspecialchars($estado_servicio) : 'Todos'; ?></p>
        <p><strong>DNI Cliente:</strong> <?php echo !empty($dni_cliente) ? htmlspecialchars($dni_cliente) : 'Todos'; ?></p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Fecha Ingreso</th>
                <th>Cliente</th>
                <th>DNI</th>
                <th>Producto</th>
                <th>Tipo</th>
                <th>Estado</th>
                <th>Entrega Estimada</th>
                <th>Costo</th>
                <th>Duración</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($servicios) > 0): ?>
                <?php foreach ($servicios as $servicio): ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($servicio['fecha_ingreso'])); ?></td>
                        <td><?php echo htmlspecialchars($servicio['cliente_apellido'] . ', ' . $servicio['cliente_nombre']); ?></td>
                        <td><?php echo htmlspecialchars($servicio['cliente_dni']); ?></td>
                        <td><?php echo htmlspecialchars($servicio['producto']); ?></td>
                        <td><?php echo htmlspecialchars($servicio['tipo']); ?></td>
                        <td><?php echo htmlspecialchars($servicio['estado']); ?></td>
                        <td><?php echo date('d/m/Y', strtotime($servicio['fecha_entrega_estimada'])); ?></td>
                        <td>$<?php echo number_format($servicio['costo_servicio'], 2); ?></td>
                        <td>
                            <?php if ($servicio['estado'] == 'COMPLETADO'): ?>
                                <?php echo $servicio['dias_reales']; ?> días
                            <?php else: ?>
                                <em>En curso</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" style="text-align: center;">No hay servicios que coincidan con los filtros aplicados.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

</body>
</html>