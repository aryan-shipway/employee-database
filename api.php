<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database configuration
class Database {
    private $host = 'localhost';
    private $username = 'root';
    private $password = 'qwertyuiop1';
    private $database = 'employee_db';
    private $connection;
    
    public function connect() {
        try {
            $this->connection = new PDO(
                "mysql:host={$this->host};dbname={$this->database}", 
                $this->username, 
                $this->password
            );
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $this->connection;
        } catch(PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
            exit();
        }
    }
}

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Initialize database connection
$database = new Database();
$db = $database->connect();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGet($db, $action);
            break;
        case 'POST':
            handlePost($db, $action);
            break;
        case 'PUT':
            handlePut($db, $action);
            break;
        case 'DELETE':
            handleDelete($db, $action);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// GET requests - Fetch employees
function handleGet($db, $action) {
    if ($action === 'employees') {
        $search = $_GET['search'] ?? '';
        
        if ($search) {
            $sql = "SELECT * FROM employees WHERE 
                    firstName LIKE :search OR 
                    lastName LIKE :search OR 
                    email LIKE :search OR 
                    department LIKE :search OR 
                    position LIKE :search 
                    ORDER BY id DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute(['search' => "%$search%"]);
        } else {
            $sql = "SELECT * FROM employees ORDER BY id DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute();
        }
        
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $employees]);
        return;
    }
    
    if ($action === 'employee' && isset($_GET['id'])) {
        $id = $_GET['id'];
        $sql = "SELECT * FROM employees WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute(['id' => $id]);
        
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($employee) {
            echo json_encode(['success' => true, 'data' => $employee]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Employee not found']);
        }
        return;
    }
    
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

// POST requests - Create new employee
function handlePost($db, $action) {
    if ($action === 'employee') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $required = ['firstName', 'lastName', 'email', 'department', 'position'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Field '$field' is required"]);
                return;
            }
        }
        
        $sql = "INSERT INTO employees (firstName, lastName, email, phone, department, position, salary, hireDate) 
                VALUES (:firstName, :lastName, :email, :phone, :department, :position, :salary, :hireDate)";
        
        try {
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([
                'firstName' => $input['firstName'],
                'lastName' => $input['lastName'],
                'email' => $input['email'],
                'phone' => $input['phone'] ?? null,
                'department' => $input['department'],
                'position' => $input['position'],
                'salary' => !empty($input['salary']) ? (float)$input['salary'] : null,
                'hireDate' => !empty($input['hireDate']) ? $input['hireDate'] : null
            ]);
            
            if ($result) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Employee added successfully',
                    'id' => $db->lastInsertId()
                ]);
            } else {
                throw new Exception('Failed to add employee');
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        return;
    }
    
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

// PUT requests - Update employee
function handlePut($db, $action) {
    if ($action === 'employee' && isset($_GET['id'])) {
        $id = $_GET['id'];
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Check if employee exists
        $checkSql = "SELECT id FROM employees WHERE id = :id";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute(['id' => $id]);
        
        if (!$checkStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Employee not found']);
            return;
        }
        
        // Check if email already exists for other employees
        if (!empty($input['email'])) {
            $emailCheckSql = "SELECT id FROM employees WHERE email = :email AND id != :id";
            $emailCheckStmt = $db->prepare($emailCheckSql);
            $emailCheckStmt->execute(['email' => $input['email'], 'id' => $id]);
            
            if ($emailCheckStmt->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Email already exists']);
                return;
            }
        }
        
        $sql = "UPDATE employees SET 
                firstName = :firstName, 
                lastName = :lastName, 
                email = :email, 
                phone = :phone, 
                department = :department, 
                position = :position, 
                salary = :salary, 
                hireDate = :hireDate
                WHERE id = :id";
        
        $stmt = $db->prepare($sql);
        $result = $stmt->execute([
            'id' => $id,
            'firstName' => $input['firstName'],
            'lastName' => $input['lastName'],
            'email' => $input['email'],
            'phone' => $input['phone'] ?? null,
            'department' => $input['department'],
            'position' => $input['position'],
            'salary' => !empty($input['salary']) ? (float)$input['salary'] : null,
            'hireDate' => !empty($input['hireDate']) ? $input['hireDate'] : null
        ]);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Employee updated successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to update employee']);
        }
        return;
    }
    
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action or missing ID']);
}

// DELETE requests - Delete employee
function handleDelete($db, $action) {
    if ($action === 'employee' && isset($_GET['id'])) {
        $id = $_GET['id'];
        
        // Check if employee exists
        $checkSql = "SELECT id FROM employees WHERE id = :id";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute(['id' => $id]);
        
        if (!$checkStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Employee not found']);
            return;
        }
        
        $sql = "DELETE FROM employees WHERE id = :id";
        $stmt = $db->prepare($sql);
        $result = $stmt->execute(['id' => $id]);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Employee deleted successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to delete employee']);
        }
        return;
    }
    
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action or missing ID']);
}
?>