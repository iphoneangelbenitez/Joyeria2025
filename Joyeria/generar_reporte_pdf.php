<?php
// generar_reporte_pdf.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Verificar permisos (solo por seguridad, aunque la consulta ya lo maneja)
$esAdministrador = ($_SESSION['user_type'] == 'ADM');

require_once "config/database.php";
$database = new Database();
$db = $database->getConnection();

// --- 1. OBTENER LOS MISMOS FILTROS ---
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');
$metodo_pago = $_GET['metodo_pago'] ?? '';
$id_vendedor = $_GET['id_vendedor'] ?? '';

// --- 2. EJECUTAR LA MISMA CONSULTA DE VENTAS ---
$query_ventas = "SELECT v.*, c.nombre as cliente_nombre, c.apellido as cliente_apellido, 
                        u.nombre as vendedor_nombre, COUNT(vd.id) as items,
                        SUM(vd.cantidad * vd.precio_unitario) as subtotal_base
                 FROM ventas v
                 INNER JOIN clientes c ON v.id_cliente = c.id
                 INNER JOIN usuarios u ON v.id_usuario = u.id
                 INNER JOIN venta_detalles vd ON v.id = vd.id_venta
                 WHERE v.fecha BETWEEN :fecha_inicio AND :fecha_fin";

$params = [
    ':fecha_inicio' => $fecha_inicio . ' 00:00:00',
    ':fecha_fin' => $fecha_fin . ' 23:59:59'
];

if (!empty($metodo_pago)) {
    $query_ventas .= " AND v.metodo_pago = :metodo_pago";
    $params[':metodo_pago'] = $metodo_pago;
}

if (!empty($id_vendedor) && $esAdministrador) {
    $query_ventas .= " AND v.id_usuario = :id_vendedor";
    $params[':id_vendedor'] = $id_vendedor;
}

$query_ventas .= " GROUP BY v.id ORDER BY v.fecha DESC";

$stmt_ventas = $db->prepare($query_ventas);
foreach ($params as $key => $value) {
    $stmt_ventas->bindValue($key, $value);
}
$stmt_ventas->execute();
$ventas = $stmt_ventas->fetchAll(PDO::FETCH_ASSOC);

// Función para formatear el método de pago (opcional, para que se vea mejor)
function formatMetodoPago($metodo) {
    switch (strtoupper($metodo)) {
        case 'EFECTIVO': return 'Efectivo';
        case 'TARJETA': return 'Tarjeta';
        case 'TRANSFERENCIA': return 'Transferencia';
        default: return $metodo;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte - Detalle de Ventas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Estilos específicos para impresión */
        @media print {
            /* Oculta botones o elementos que no queremos imprimir */
            .no-print {
                display: none !important;
            }
            /* Asegura que los estilos de la tabla se apliquen */
            table {
                width: 100%;
                border-collapse: collapse;
            }
            th, td {
                border: 1px solid #dee2e6 !important;
                padding: 0.5rem !important;
            }
            thead {
                /* Repite el encabezado en cada página */
                display: table-header-group !important;
            }
            /* Mejora el contraste para impresión */
            body {
                color: #000 !important;
                background-color: #fff !important;
            }
        }
        
        /* Estilos generales para la página de impresión */
        body {
            padding: 2rem;
            font-family: Arial, sans-serif;
        }
        h1, h4 {
            margin-bottom: 1rem;
        }
        .filter-info {
            font-size: 0.9rem;
            color: #555;
            margin-bottom: 1.5rem;
        }
        table {
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <h1>Reporte - Detalle de Ventas</h1>
        <div class="filter-info">
            <p class="mb-0">
                <strong>Período:</strong>
                <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> al <?php echo date('d/m/Y', strtotime($fecha_fin)); ?>
            </p>
            <p class="mb-0">
                <strong>Método de Pago:</strong>
                <?php echo empty($metodo_pago) ? 'Todos' : formatMetodoPago($metodo_pago); ?>
            </p>
            <?php if ($esAdministrador && !empty($id_vendedor)): ?>
                <p class="mb-0">
                    <strong>Vendedor ID:</strong> <?php echo htmlspecialchars($id_vendedor); ?>
                </p>
            <?php endif; ?>
        </div>

        <?php if (count($ventas) > 0): ?>
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Cliente</th>
                        <th>Vendedor</th>
                        <th>Método Pago</th>
                        <th>Items</th>
                        <th>Descuento</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $totalGeneral = 0;
                    foreach ($ventas as $venta): 
                        $totalGeneral += $venta['total'];
                    ?>
                        <tr>
                            <td><?php echo date('d/m/Y H:i', strtotime($venta['fecha'])); ?></td>
                            <td><?php echo htmlspecialchars($venta['cliente_apellido'] . ', ' . $venta['cliente_nombre']); ?></td>
                            <td><?php echo htmlspecialchars($venta['vendedor_nombre']); ?></td>
                            <td><?php echo formatMetodoPago($venta['metodo_pago']); ?></td>
                            <td><?php echo $venta['items']; ?></td>
                            <td><?GNP: <?php echo $venta['descuento']; ?>%</td>
                            <td><strong>$<?php echo number_format($venta['total'], 2); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="6" class="text-end"><strong>Total General:</strong></td>
                        <td><strong>$<?php echo number_format($totalGeneral, 2); ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        <?php else: ?>
            <div class="alert alert-info">No hay ventas que coincidan con los filtros aplicados.</div>
        <?php endif; ?>

        <div class="text-center mt-4 no-print">
            <button class="btn btn-primary" onclick="window.print()">Imprimir Reporte</button>
            <button class="btn btn-secondary" onclick="window.close()">Cerrar Ventana</button>
        </div>
    </div>

    <script>
        // Dispara el diálogo de impresión automáticamente al cargar la página
        window.onload = function() {
            window.print();
        };
    </script>
</body>
</html>