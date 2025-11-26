<?php
// File: api_produk.php
require_once 'config.php';

// GET - Ambil semua produk
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $search = isset($_GET['search']) ? $_GET['search'] : '';
    $kategori = isset($_GET['kategori']) ? $_GET['kategori'] : '';
    
    $sql = "SELECT * FROM kasir_produk WHERE 1=1";
    $params = [];
    $types = "";
    
    if (!empty($search)) {
        $sql .= " AND NamaProduk LIKE ?";
        $params[] = "%$search%";
        $types .= "s";
    }
    
    if (!empty($kategori) && $kategori !== 'all') {
        $sql .= " AND Kategori = ?";
        $params[] = $kategori;
        $types .= "s";
    }
    
    $sql .= " ORDER BY NamaProduk";
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $products = [];
    while($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    
    echo json_encode($products);
    exit;
}

// POST - Tambah produk baru
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid JSON data"]);
        exit;
    }
    
    // Validasi input
    if (!isset($input['NamaProduk']) || empty(trim($input['NamaProduk']))) {
        http_response_code(400);
        echo json_encode(["error" => "Nama produk harus diisi"]);
        exit;
    }
    
    if (!isset($input['Harga']) || $input['Harga'] <= 0) {
        http_response_code(400);
        echo json_encode(["error" => "Harga harus lebih dari 0"]);
        exit;
    }
    
    if (!isset($input['Stok']) || $input['Stok'] < 0) {
        http_response_code(400);
        echo json_encode(["error" => "Stok tidak boleh negatif"]);
        exit;
    }
    
    $namaProduk = trim($input['NamaProduk']);
    $harga = floatval($input['Harga']);
    $stok = intval($input['Stok']);
    $kategori = isset($input['Kategori']) ? $input['Kategori'] : 'umum';
    
    // Cek apakah produk sudah ada
    $checkSql = "SELECT ProdukID FROM kasir_produk WHERE NamaProduk = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("s", $namaProduk);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        http_response_code(409);
        echo json_encode(["error" => "Produk dengan nama tersebut sudah ada"]);
        exit;
    }
    
    // Insert produk baru
    $sql = "INSERT INTO kasir_produk (NamaProduk, Harga, Stok, Kategori) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sdis", $namaProduk, $harga, $stok, $kategori);
    
    if ($stmt->execute()) {
        echo json_encode([
            "success" => true, 
            "message" => "Produk berhasil ditambahkan",
            "produkID" => $conn->insert_id
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Gagal menambahkan produk: " . $stmt->error]);
    }
    exit;
}

// PUT - Update produk
if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid JSON data"]);
        exit;
    }
    
    if (!isset($input['ProdukID']) || !isset($input['NamaProduk']) || !isset($input['Harga']) || !isset($input['Stok'])) {
        http_response_code(400);
        echo json_encode(["error" => "Data tidak lengkap"]);
        exit;
    }
    
    $produkID = intval($input['ProdukID']);
    $namaProduk = trim($input['NamaProduk']);
    $harga = floatval($input['Harga']);
    $stok = intval($input['Stok']);
    $kategori = isset($input['Kategori']) ? $input['Kategori'] : 'umum';
    
    // Cek apakah nama produk sudah digunakan oleh produk lain
    $checkSql = "SELECT ProdukID FROM kasir_produk WHERE NamaProduk = ? AND ProdukID != ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("si", $namaProduk, $produkID);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        http_response_code(409);
        echo json_encode(["error" => "Produk dengan nama tersebut sudah ada"]);
        exit;
    }
    
    $sql = "UPDATE kasir_produk SET NamaProduk = ?, Harga = ?, Stok = ?, Kategori = ? WHERE ProdukID = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sdisi", $namaProduk, $harga, $stok, $kategori, $produkID);
    
    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Produk berhasil diupdate"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Gagal mengupdate produk: " . $stmt->error]);
    }
    exit;
}

// DELETE - Hapus produk
if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid JSON data"]);
        exit;
    }
    
    if (!isset($input['ProdukID'])) {
        http_response_code(400);
        echo json_encode(["error" => "ProdukID tidak diberikan"]);
        exit;
    }
    
    $produkID = intval($input['ProdukID']);
    
    // Cek apakah produk digunakan dalam transaksi
    $checkSql = "SELECT DetailID FROM kasir_detailpenjualan WHERE ProdukID = ? LIMIT 1";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("i", $produkID);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        http_response_code(409);
        echo json_encode(["error" => "Produk tidak dapat dihapus karena sudah digunakan dalam transaksi"]);
        exit;
    }
    
    $sql = "DELETE FROM kasir_produk WHERE ProdukID = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $produkID);
    
    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Produk berhasil dihapus"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Gagal menghapus produk: " . $stmt->error]);
    }
    exit;
}

http_response_code(405);
echo json_encode(["error" => "Method not allowed"]);
?>