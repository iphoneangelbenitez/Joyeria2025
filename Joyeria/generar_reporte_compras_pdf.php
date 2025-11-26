<?php
// generar_reporte_compras_pdf.php
session_start();
if (!isset($_SESSION['user_id'])) {
    die('No autorizado');
}

require_once "config/database.php";

// Incluir la librería FPDF
require_once 'fpdf/fpdf.php';

class PDF extends FPDF
{
    private $titulo;
    private $fecha_inicio;
    private $fecha_fin;
    
    function setTitulo($titulo, $fecha_inicio, $fecha_fin) {
        $this->titulo = $titulo;
        $this->fecha_inicio = $fecha_inicio;
        $this->fecha_fin = $fecha_fin;
    }
    
    // Cabecera de página
    function Header() {
        // Logo
        $this->Image('assets/images/logo.png', 10, 8, 33);
        // Arial bold 15
        $this->SetFont('Arial', 'B', 15);
        // Movernos a la derecha
        $this->Cell(80);
        // Título
        $this->Cell(30, 10, $this->titulo, 0, 0, 'C');
        // Salto de línea
        $this->Ln(20);
        
        // Información del reporte
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 6, 'Periodo: ' . date('d/m/Y', strtotime($this->fecha_inicio)) . ' - ' . date('d/m/Y', strtotime($this->fecha_fin)), 0, 1);
        $this->Cell(0, 6, 'Generado: ' . date('d/m/Y H:i:s'), 0, 1);
        $this->Cell(0, 6, 'Usuario: ' . $_SESSION['user_name'], 0, 1);
        $this->Ln(5);
    }
    
    // Pie de página
    function Footer() {
        // Posición: a 1,5 cm del final
        $this->SetY(-15);
        // Arial italic 8
        $this->SetFont('Arial', 'I', 8);
        // Número de página
        $this->Cell(0, 10, 'Pagina ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
    
    // Tabla coloreada
    function ImprovedTable($header, $data) {
        // Colores, ancho de línea y fuente en negrita
        $this->SetFillColor(212, 175, 55);
        $this->SetTextColor(255);
        $this->SetDrawColor(128, 0, 0);
        $this->SetLineWidth(.3);
        $this->SetFont('', 'B');
        
        // Anchuras de las columnas
        $w = array(15, 25, 45, 40, 25, 20, 20);
        
        // Cabecera
        for($i = 0; $i < count($header); $i++)
            $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C', true);
        $this->Ln();
        
        // Restauración de colores y fuentes
        $this->SetFillColor(224, 235, 255);
        $this->SetTextColor(0);
        $this->SetFont('');
        
        // Datos
        $fill = false;
        $total_general = 0;
        $contador = 0;
        
        foreach($data as $row) {
            $contador++;
            $this->Cell($w[0], 6, $contador, 'LR', 0, 'C', $fill);
            $this->Cell($w[1], 6, date('d/m/Y', strtotime($row['fecha_compra'])), 'LR', 0, 'C', $fill);
            $this->Cell($w[2], 6, substr($row['empresa'], 0, 25), 'LR', 0, 'L', $fill);
            $this->Cell($w[3], 6, $row['usuario_nombre'], 'LR', 0, 'L', $fill);
            $this->Cell($w[4], 6, $row['forma_pago'], 'LR', 0, 'C', $fill);
            $this->Cell($w[5], 6, $row['items'], 'LR', 0, 'C', $fill);
            $this->Cell($w[6], 6, '$' . number_format($row['total'], 2), 'LR', 0, 'R', $fill);
            $this->Ln();
            
            $fill = !$fill;
            $total_general += $row['total'];
        }
        
        // Línea de cierre
        $this->Cell(array_sum($w), 0, '', 'T');
        $this->Ln();
        
        // Total general
        $this->SetFont('', 'B');
        $this->Cell(array_sum($w) - $w[6], 6, 'TOTAL GENERAL:', 0, 0, 'R');
        $this->Cell($w[6], 6, '$' . number_format($total_general, 2), 0, 0, 'R');
    }
    
    // Tabla de estadísticas
    function StatsTable($estadisticas) {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'Estadisticas del Periodo', 0, 1);
        $this->SetFont('Arial', '', 10);
        
        $this->Cell(60, 6, 'Total de Compras:', 0, 0);
        $this->Cell(30, 6, $estadisticas['total_compras'], 0, 1);
        
        $this->Cell(60, 6, 'Monto Total:', 0, 0);
        $this->Cell(30, 6, '$' . number_format($estadisticas['monto_total_compras'], 2), 0, 1);
        
        $this->Cell(60, 6, 'Promedio por Compra:', 0, 0);
        $this->Cell(30, 6, '$' . number_format($estadisticas['promedio_compra'], 2), 0, 1);
        
        $this->Cell(60, 6, 'Unidades Compradas:', 0, 0);
        $this->Cell(30, 6, $estadisticas['total_unidades_compradas'], 0, 1);
        
        $this->Cell(60, 6, 'Proveedores Diferentes:', 0, 0);
        $this->Cell(30, 6, $estadisticas['proveedores_diferentes'], 0, 1);
        
        $this->Ln(10);
    }
}

try {
    $database = new Database();
    $db = $database->getConnection();

    // Obtener parámetros de filtro
    $fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
    $fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');
    $id_proveedor = $_GET['id_proveedor'] ?? '';
    $forma_pago = $_GET['forma_pago'] ?? '';
    $id_usuario = $_GET['id_usuario'] ?? '';

    // Construir consulta base para compras (misma que en reportes_compras.php)
    $query_compras = "SELECT c.*, 
                             p.nombre as proveedor_nombre, p.apellido as proveedor_apellido, p.empresa,
                             u.nombre as usuario_nombre,
                             COUNT(cd.id) as items,
                             SUM(cd.cantidad) as total_unidades
                      FROM compras c
                      INNER JOIN proveedores p ON c.id_proveedor = p.id
                      INNER JOIN usuarios u ON c.id_usuario = u.id
                      INNER JOIN compra_detalles cd ON c.id = cd.id_compra
                      WHERE c.fecha_compra BETWEEN :fecha_inicio AND :fecha_fin";

    $params = [
        ':fecha_inicio' => $fecha_inicio,
        ':fecha_fin' => $fecha_fin
    ];

    // Aplicar filtros adicionales
    if (!empty($id_proveedor)) {
        $query_compras .= " AND c.id_proveedor = :id_proveedor";
        $params[':id_proveedor'] = $id_proveedor;
    }

    if (!empty($forma_pago)) {
        $query_compras .= " AND c.forma_pago = :forma_pago";
        $params[':forma_pago'] = $forma_pago;
    }

    if (!empty($id_usuario)) {
        $query_compras .= " AND c.id_usuario = :id_usuario";
        $params[':id_usuario'] = $id_usuario;
    }

    $query_compras .= " GROUP BY c.id ORDER BY c.fecha_compra DESC";

    // Preparar y ejecutar consulta de compras
    $stmt_compras = $db->prepare($query_compras);
    foreach ($params as $key => $value) {
        $stmt_compras->bindValue($key, $value);
    }
    $stmt_compras->execute();
    $compras = $stmt_compras->fetchAll(PDO::FETCH_ASSOC);

    // Consulta para estadísticas de compras
    $query_estadisticas = "SELECT 
        COUNT(*) as total_compras,
        SUM(c.total) as monto_total_compras,
        AVG(c.total) as promedio_compra,
        SUM(cd.cantidad) as total_unidades_compradas,
        COUNT(DISTINCT c.id_proveedor) as proveedores_diferentes
    FROM compras c
    INNER JOIN compra_detalles cd ON c.id = cd.id_compra
    WHERE c.fecha_compra BETWEEN :fecha_inicio AND :fecha_fin";

    $params_estadisticas = [
        ':fecha_inicio' => $fecha_inicio,
        ':fecha_fin' => $fecha_fin
    ];

    if (!empty($id_proveedor)) {
        $query_estadisticas .= " AND c.id_proveedor = :id_proveedor";
        $params_estadisticas[':id_proveedor'] = $id_proveedor;
    }

    if (!empty($forma_pago)) {
        $query_estadisticas .= " AND c.forma_pago = :forma_pago";
        $params_estadisticas[':forma_pago'] = $forma_pago;
    }

    $stmt_estadisticas = $db->prepare($query_estadisticas);
    foreach ($params_estadisticas as $key => $value) {
        $stmt_estadisticas->bindValue($key, $value);
    }
    $stmt_estadisticas->execute();
    $estadisticas = $stmt_estadisticas->fetch(PDO::FETCH_ASSOC);

    // Crear instancia de PDF
    $pdf = new PDF();
    $pdf->setTitulo('REPORTE DE COMPRAS', $fecha_inicio, $fecha_fin);
    $pdf->AliasNbPages();
    $pdf->AddPage();
    
    // Información de filtros aplicados
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Filtros Aplicados:', 0, 1);
    $pdf->SetFont('Arial', '', 9);
    
    $filtros_texto = "Periodo: " . date('d/m/Y', strtotime($fecha_inicio)) . " - " . date('d/m/Y', strtotime($fecha_fin));
    
    if (!empty($id_proveedor)) {
        // Obtener nombre del proveedor
        $query_prov = "SELECT empresa FROM proveedores WHERE id = :id";
        $stmt_prov = $db->prepare($query_prov);
        $stmt_prov->bindValue(':id', $id_proveedor);
        $stmt_prov->execute();
        $proveedor = $stmt_prov->fetch(PDO::FETCH_ASSOC);
        $filtros_texto .= " | Proveedor: " . $proveedor['empresa'];
    }
    
    if (!empty($forma_pago)) {
        $filtros_texto .= " | Forma de Pago: " . $forma_pago;
    }
    
    if (!empty($id_usuario)) {
        // Obtener nombre del usuario
        $query_user = "SELECT nombre FROM usuarios WHERE id = :id";
        $stmt_user = $db->prepare($query_user);
        $stmt_user->bindValue(':id', $id_usuario);
        $stmt_user->execute();
        $usuario = $stmt_user->fetch(PDO::FETCH_ASSOC);
        $filtros_texto .= " | Usuario: " . $usuario['nombre'];
    }
    
    $pdf->MultiCell(0, 5, $filtros_texto);
    $pdf->Ln(5);

    // Mostrar estadísticas
    $pdf->StatsTable($estadisticas);

    // Cabecera de la tabla
    $header = array('#', 'Fecha', 'Proveedor', 'Usuario', 'Pago', 'Items', 'Total');

    // Mostrar tabla de compras
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Detalle de Compras', 0, 1);
    $pdf->ImprovedTable($header, $compras);
    
    // Información adicional
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Cell(0, 10, 'Reporte generado por Sistema de Joyeria - ' . date('d/m/Y H:i:s'), 0, 1, 'C');

    // Salida del PDF
    $pdf->Output('I', 'reporte_compras_' . date('Ymd_His') . '.pdf');

} catch (Exception $e) {
    die('Error al generar el reporte: ' . $e->getMessage());
}
?>