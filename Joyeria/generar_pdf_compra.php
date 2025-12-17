<?php
// generar_pdf_compra.php
session_start();
if (!isset($_SESSION['user_id'])) {
    die('No autorizado');
}

require_once "config/database.php";
require_once 'fpdf/fpdf.php';

class PDF_Compra extends FPDF
{
    private $compra_data;
    
    function setCompraData($data) {
        $this->compra_data = $data;
    }
    
    function Header() {
        // Logo
        $this->Image('assets/images/logo.png', 10, 8, 33);
        // Arial bold 15
        $this->SetFont('Arial', 'B', 15);
        // Movernos a la derecha
        $this->Cell(80);
        // Título
        $this->Cell(30, 10, 'COMPROBANTE DE COMPRA', 0, 0, 'C');
        // Salto de línea
        $this->Ln(20);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Pagina ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
    
    function InfoCompra() {
        $this->SetFont('Arial', '', 10);
        
        // Información de la compra
        $this->Cell(0, 6, 'Compra #: ' . $this->compra_data['id'], 0, 1);
        $this->Cell(0, 6, 'Fecha: ' . date('d/m/Y', strtotime($this->compra_data['fecha_compra'])), 0, 1);
        $this->Cell(0, 6, 'Proveedor: ' . $this->compra_data['empresa'], 0, 1);
        $this->Cell(0, 6, 'Forma de Pago: ' . $this->compra_data['forma_pago'], 0, 1);
        $this->Cell(0, 6, 'Registrado por: ' . $this->compra_data['usuario_nombre'], 0, 1);
        
        if (!empty($this->compra_data['observaciones'])) {
            $this->Ln(3);
            $this->MultiCell(0, 6, 'Observaciones: ' . $this->compra_data['observaciones']);
        }
        
        $this->Ln(10);
    }
    
    function TablaDetalles($detalles) {
        // Cabecera
        $this->SetFillColor(212, 175, 55);
        $this->SetTextColor(255);
        $this->SetDrawColor(128, 0, 0);
        $this->SetLineWidth(.3);
        $this->SetFont('', 'B');
        
        $w = array(80, 25, 35, 40);
        $header = array('Producto', 'Cantidad', 'Precio Unit.', 'Subtotal');
        
        for($i = 0; $i < count($header); $i++)
            $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C', true);
        $this->Ln();
        
        // Restauración de colores y fuentes
        $this->SetFillColor(224, 235, 255);
        $this->SetTextColor(0);
        $this->SetFont('');
        
        // Datos
        $fill = false;
        $total = 0;
        
        foreach($detalles as $detalle) {
            $this->Cell($w[0], 6, $detalle['producto_nombre'], 'LR', 0, 'L', $fill);
            $this->Cell($w[1], 6, $detalle['cantidad'], 'LR', 0, 'C', $fill);
            $this->Cell($w[2], 6, '$' . number_format($detalle['costo_unitario'], 2), 'LR', 0, 'R', $fill);
            $this->Cell($w[3], 6, '$' . number_format($detalle['subtotal'], 2), 'LR', 0, 'R', $fill);
            $this->Ln();
            
            $fill = !$fill;
            $total += $detalle['subtotal'];
        }
        
        // Línea de cierre
        $this->Cell(array_sum($w), 0, '', 'T');
        $this->Ln();
        
        // Total
        $this->SetFont('', 'B');
        $this->Cell(array_sum($w) - $w[3], 6, 'TOTAL:', 0, 0, 'R');
        $this->Cell($w[3], 6, '$' . number_format($total, 2), 0, 0, 'R');
    }
}

// Verificar que se proporcionó el ID de compra
if (!isset($_GET['id_compra']) || empty($_GET['id_compra'])) {
    die('ID de compra no proporcionado');
}

$id_compra = $_GET['id_compra'];

try {
    $database = new Database();
    $db = $database->getConnection();

    // Obtener datos de la compra
    $query_compra = "SELECT c.*, p.empresa, u.nombre as usuario_nombre 
                     FROM compras c
                     INNER JOIN proveedores p ON c.id_proveedor = p.id
                     INNER JOIN usuarios u ON c.id_usuario = u.id
                     WHERE c.id = :id_compra";
    
    $stmt_compra = $db->prepare($query_compra);
    $stmt_compra->bindValue(':id_compra', $id_compra);
    $stmt_compra->execute();
    $compra = $stmt_compra->fetch(PDO::FETCH_ASSOC);
    
    if (!$compra) {
        die('Compra no encontrada');
    }
    
    // Obtener detalles de la compra
    $query_detalles = "SELECT cd.*, pr.nombre as producto_nombre
                       FROM compra_detalles cd
                       INNER JOIN productos pr ON cd.id_producto = pr.id
                       WHERE cd.id_compra = :id_compra";
    
    $stmt_detalles = $db->prepare($query_detalles);
    $stmt_detalles->bindValue(':id_compra', $id_compra);
    $stmt_detalles->execute();
    $detalles = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);
    
    // Crear PDF
    $pdf = new PDF_Compra();
    $pdf->setCompraData($compra);
    $pdf->AliasNbPages();
    $pdf->AddPage();
    
    // Información de la compra
    $pdf->InfoCompra();
    
    // Tabla de detalles
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Detalles de la Compra', 0, 1);
    $pdf->TablaDetalles($detalles);
    
    // Salida
    $pdf->Output('I', 'compra_' . $id_compra . '_' . date('Ymd_His') . '.pdf');

} catch (Exception $e) {
    die('Error al generar el PDF: ' . $e->getMessage());
}
?>