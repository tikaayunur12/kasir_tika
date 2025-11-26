<?php
// File: api_penjualan.php (FIXED VERSION)
require_once 'config.php';

// GET - Ambil data penjualan
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $sql = "SELECT p.*, pl.NamaPelanggan 
            FROM kasir_penjualan p 
            LEFT JOIN kasir_pelanggan pl ON p.PelangganID = pl.PelangganID 
            ORDER BY p.TanggalPenjualan DESC 
            LIMIT 50";
    
    $result = $conn->query($sql);
    
    $penjualan = [];
    while($row = $result->fetch_assoc()) {
        $penjualan[] = $row;
    }
    
    echo json_encode($penjualan);
    exit;
}

// POST - Buat transaksi penjualan baru (FIXED)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid JSON data"]);
        exit;
    }
    
    // Validasi input
    if (!isset($input['items']) || !is_array($input['items']) || empty($input['items'])) {
        http_response_code(400);
        echo json_encode(["error" => "Data items tidak valid"]);
        exit;
    }
    
    if (!isset($input['totalHarga']) || $input['totalHarga'] <= 0) {
        http_response_code(400);
        echo json_encode(["error" => "Total harga tidak valid"]);
        exit;
    }
    
    // Mulai transaction
    $conn->begin_transaction();
    
    try {
        // 1. Insert ke tabel penjualan dengan kolom yang benar
        $tanggal = date('Y-m-d H:i:s');
        $totalHarga = floatval($input['totalHarga']);
        $pelangganID = isset($input['pelangganID']) ? intval($input['pelangganID']) : NULL;
        $paymentMethod = isset($input['paymentMethod']) ? $conn->real_escape_string($input['paymentMethod']) : 'cash';
        $amountPaid = isset($input['amountPaid']) ? floatval($input['amountPaid']) : $totalHarga;
        $changeAmount = isset($input['change']) ? floatval($input['change']) : 0;
        
        // Gunakan kolom yang sesuai dengan struktur database
        $sql = "INSERT INTO kasir_penjualan (TanggalPenjualan, TotalHarga, PelangganID, PaymentMethod, AmountPaid, ChangeAmount) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("sdisdd", $tanggal, $totalHarga, $pelangganID, $paymentMethod, $amountPaid, $changeAmount);
        
        if (!$stmt->execute()) {
            throw new Exception("Gagal menyimpan data penjualan: " . $stmt->error);
        }
        
        $penjualanID = $conn->insert_id;
        
        // 2. Insert detail penjualan dan update stok
        $items = $input['items'];
        foreach ($items as $item) {
            // Validasi item
            if (!isset($item['ProdukID']) || !isset($item['quantity']) || $item['quantity'] <= 0) {
                throw new Exception("Data item tidak valid");
            }
            
            $produkID = intval($item['ProdukID']);
            $quantity = intval($item['quantity']);
            $subtotal = floatval($item['subtotal']);
            
            // Cek stok tersedia
            $checkStokSql = "SELECT Stok, NamaProduk FROM kasir_produk WHERE ProdukID = ?";
            $checkStokStmt = $conn->prepare($checkStokSql);
            $checkStokStmt->bind_param("i", $produkID);
            $checkStokStmt->execute();
            $checkResult = $checkStokStmt->get_result();
            
            if ($checkResult->num_rows === 0) {
                throw new Exception("Produk dengan ID $produkID tidak ditemukan");
            }
            
            $produk = $checkResult->fetch_assoc();
            
            if ($produk['Stok'] < $quantity) {
                throw new Exception("Stok " . $produk['NamaProduk'] . " tidak mencukupi. Stok tersedia: " . $produk['Stok']);
            }
            
            // Insert detail penjualan
            $sql = "INSERT INTO kasir_detailpenjualan (PenjualanID, ProdukID, JumlahProduk, Subtotal) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Prepare detail failed: " . $conn->error);
            }
            
            $stmt->bind_param("iiid", $penjualanID, $produkID, $quantity, $subtotal);
            
            if (!$stmt->execute()) {
                throw new Exception("Gagal menyimpan detail penjualan: " . $stmt->error);
            }
            
            // Update stok produk
            $sql = "UPDATE kasir_produk SET Stok = Stok - ? WHERE ProdukID = ?";
            $stmt = $conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Prepare update stok failed: " . $conn->error);
            }
            
            $stmt->bind_param("ii", $quantity, $produkID);
            
            if (!$stmt->execute()) {
                throw new Exception("Gagal update stok produk: " . $stmt->error);
            }
        }
        
        $conn->commit();
        echo json_encode([
            "success" => true, 
            "penjualanID" => $penjualanID,
            "message" => "Transaksi berhasil diproses"
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

// Method not allowed
http_response_code(405);
echo json_encode(["error" => "Method not allowed"]);
?>