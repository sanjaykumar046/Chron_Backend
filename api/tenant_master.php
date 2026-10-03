<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database connection
$host = 'chron-db.cd6wkwiowv2u.ap-southeast-2.rds.amazonaws.com';
$dbname = 'prod_ent1_master';
$username = 'admin';
$password = 'wfxicVdxG71bjvdVhFN3';


// $servername = "localhost";
// $username = "root";
// $password = "Sanjaykumar@7";
// $dbname = "prod_ent1_tenant_0_demo";
// CHANGE THIS TO YOUR OWN SECURE 32-CHARACTER KEY
$ENCRYPTION_KEY = '.!F1a*(c51chVo,^$�!OW-T�_0GJr};r';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'fetch_all':
        fetchAllTenants($pdo);
        break;
    
    case 'get_next_id':
        getNextTenantId($pdo);
        break;
    
    case 'check_exists':
        checkExists($pdo);
        break;
    
    case 'create':
        createTenant($pdo);
        break;
    
    case 'update':
        updateTenant($pdo);
        break;
    
    case 'create_schema':
        createTenantSchema($pdo);
        break;
    
    case 'get_all_schemas':
        getAllSchemas($pdo);
        break;
    
    case 'export_import_schema':
        exportImportSchema($pdo);
        break;
    
    case 'provision_db':
        provisionDatabase($pdo);
        break;
    
    case 'go_live':
        goLive($pdo);
        break;
    
    case 'delete':
        deleteTenant($pdo);
        break;
    
    case 'decrypt_password':
        decryptPassword($pdo);
        break;
    
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

// Fetch all tenants using stored procedure
function fetchAllTenants($pdo) {
    try {
        // Try direct query instead of stored procedure to ensure we get master_id
        $stmt = $pdo->prepare("SELECT * FROM tenant_master ORDER BY tenant_id DESC");
        $stmt->execute();
        $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $tenants]);
    } catch (PDOException $e) {
        // If direct query fails, try stored procedure
        try {
            $stmt = $pdo->prepare("CALL PR_GET_TENANT_MASTER('ALL', 'ALL')");
            $stmt->execute();
            $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            echo json_encode(['success' => true, 'data' => $tenants]);
        } catch (PDOException $e2) {
            echo json_encode(['success' => false, 'message' => 'Failed to fetch tenants: ' . $e2->getMessage()]);
        }
    }
}

// Get next tenant ID
function getNextTenantId($pdo) {
    try {
        // Query: SELECT MAX(tenant_id) + 1 FROM tenant_master
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(tenant_id), 0) + 1 AS next_id FROM tenant_master");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'next_id' => $result['next_id']]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to get next ID: ' . $e->getMessage()]);
    }
}

// Check if company name or short name exists
function checkExists($pdo) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $field = $data['field']; // 'company_name' or 'company_short_name'
        $value = $data['value'];
        $excludeId = $data['exclude_id'] ?? null;
        
        $sql = "SELECT COUNT(*) FROM tenant_master WHERE $field = ?";
        $params = [$value];
        
        // Exclude current record when editing
        if ($excludeId) {
            $sql .= " AND master_id != ?";
            $params[] = $excludeId;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $count = $stmt->fetchColumn();
        
        echo json_encode(['success' => true, 'exists' => $count > 0]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to check existence: ' . $e->getMessage()]);
    }
}

function createTenant($pdo) {
    global $ENCRYPTION_KEY;
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate mandatory fields
        $mandatoryFields = ['company_name', 'company_short_name', 'license_start_date', 
                           'license_end_date', 'admin_username', 'admin_password', 'status'];
        
        foreach ($mandatoryFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                echo json_encode(['success' => false, 'message' => "Mandatory field '$field' is required"]);
                return;
            }
        }
        
        // Check if tenant_id already exists
        if (isset($data['tenant_id'])) {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM tenant_master WHERE tenant_id = ?");
            $checkStmt->execute([$data['tenant_id']]);
            if ($checkStmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Tenant ID already exists']);
                return;
            }
        }
        
        // Check if company_name already exists
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM tenant_master WHERE company_name = ?");
        $checkStmt->execute([$data['company_name']]);
        if ($checkStmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'Company name already exists. Please use a unique name.']);
            return;
        }
        
        // Check if company_short_name already exists
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM tenant_master WHERE company_short_name = ?");
        $checkStmt->execute([$data['company_short_name']]);
        if ($checkStmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'Company short name already exists. Please use a unique name.']);
            return;
        }
        
        // Store plain password temporarily for email
        $plainPassword = $data['admin_password'];
        
        // Encrypt password using AES (can be decrypted when needed)
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encryptedPassword = openssl_encrypt($plainPassword, 'aes-256-cbc', $ENCRYPTION_KEY, 0, $iv);
        // Store IV with encrypted password (separated by ::)
        $storedPassword = base64_encode($iv) . '::' . $encryptedPassword;
        
        $stmt = $pdo->prepare("
            INSERT INTO tenant_master (
                tenant_id, company_name, company_short_name, contact_person, 
                phone_number, phone_number_secondary, email_address, email_address_secondary,
                license_key, license_start_date, license_end_date, users_limit,
                admin_username, admin_password, status, provisioning_status, comments, created_on, updated_on
            ) VALUES (
                :tenant_id, :company_name, :company_short_name, :contact_person,
                :phone_number, :phone_number_secondary, :email_address, :email_address_secondary,
                :license_key, :license_start_date, :license_end_date, :users_limit,
                :admin_username, :admin_password, :status, 'CLIENT ADDED', :comments, NOW(), NOW()
            )
        ");
        
        $stmt->execute([
            ':tenant_id' => $data['tenant_id'],
            ':company_name' => $data['company_name'],
            ':company_short_name' => $data['company_short_name'],
            ':contact_person' => $data['contact_person'] ?? null,
            ':phone_number' => $data['phone_number'] ?? null,
            ':phone_number_secondary' => $data['phone_number_secondary'] ?? null,
            ':email_address' => $data['email_address'] ?? null,
            ':email_address_secondary' => $data['email_address_secondary'] ?? null,
            ':license_key' => $data['license_key'] ?? null,
            ':license_start_date' => $data['license_start_date'],
            ':license_end_date' => $data['license_end_date'],
            ':users_limit' => $data['users_limit'] ?? null,
            ':admin_username' => $data['admin_username'],
            ':admin_password' => $storedPassword,
            ':status' => $data['status'],
            ':comments' => $data['comments'] ?? null
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Tenant created successfully', 'plain_password' => $plainPassword]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to create tenant: ' . $e->getMessage()]);
    }
}

function updateTenant($pdo) {
    global $ENCRYPTION_KEY;
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['master_id'])) {
            echo json_encode(['success' => false, 'message' => 'Master ID is required']);
            return;
        }
        
        // Check if company_name already exists (excluding current record)
        if (isset($data['company_name'])) {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM tenant_master WHERE company_name = ? AND master_id != ?");
            $checkStmt->execute([$data['company_name'], $data['master_id']]);
            if ($checkStmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Company name already exists. Please use a unique name.']);
                return;
            }
        }
        
        // Check if company_short_name already exists (excluding current record)
        if (isset($data['company_short_name'])) {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM tenant_master WHERE company_short_name = ? AND master_id != ?");
            $checkStmt->execute([$data['company_short_name'], $data['master_id']]);
            if ($checkStmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Company short name already exists. Please use a unique name.']);
                return;
            }
        }
        
        // Build update query dynamically
        $fields = [];
        $params = [':master_id' => $data['master_id']];
        
        $allowedFields = [
            'tenant_id', 'company_name', 'company_short_name', 'contact_person',
            'phone_number', 'phone_number_secondary', 'email_address', 'email_address_secondary',
            'license_key', 'license_start_date', 'license_end_date', 'users_limit',
            'admin_username', 'status', 'comments'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        
        // Handle password update separately (encrypt for storage)
        if (isset($data['admin_password']) && !empty($data['admin_password'])) {
            $plainPassword = $data['admin_password'];
            $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
            $encryptedPassword = openssl_encrypt($plainPassword, 'aes-256-cbc', $ENCRYPTION_KEY, 0, $iv);
            $storedPassword = base64_encode($iv) . '::' . $encryptedPassword;
            
            $fields[] = "admin_password = :admin_password";
            $params[':admin_password'] = $storedPassword;
        }
        
        $fields[] = "updated_on = NOW()";
        
        $sql = "UPDATE tenant_master SET " . implode(', ', $fields) . " WHERE master_id = :master_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        echo json_encode(['success' => true, 'message' => 'Tenant updated successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to update tenant: ' . $e->getMessage()]);
    }
}

// Create tenant schema using stored procedure
function createTenantSchema($pdo) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['tenant_id'])) {
            echo json_encode(['success' => false, 'message' => 'Tenant ID is required']);
            return;
        }
        
        $tenantId = $data['tenant_id'];
        
        // Check if schema already exists for this tenant
        $checkStmt = $pdo->prepare("SELECT tenant_schema, provisioning_status FROM tenant_master WHERE tenant_id = ?");
        $checkStmt->execute([$tenantId]);
        $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['tenant_schema'])) {
            echo json_encode(['success' => false, 'message' => 'Database schema already exists for this tenant']);
            return;
        }
        
        // Call stored procedure: CALL PR_CREATE_TENANT_SCHEMA(tenant_id)
        $stmt = $pdo->prepare("CALL PR_CREATE_TENANT_SCHEMA(?)");
        $stmt->execute([$tenantId]);
        $stmt->closeCursor();
        
        // Update provisioning status to SCHEMA CREATED
        $updateStmt = $pdo->prepare("UPDATE tenant_master SET provisioning_status = 'SCHEMA CREATED', updated_on = NOW() WHERE tenant_id = ?");
        $updateStmt->execute([$tenantId]);
        
        echo json_encode(['success' => true, 'message' => 'Database schema created successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to create schema: ' . $e->getMessage()]);
    }
}

// Get all schemas from database
function getAllSchemas($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys') ORDER BY SCHEMA_NAME");
        $stmt->execute();
        $schemas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo json_encode(['success' => true, 'schemas' => $schemas]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch schemas: ' . $e->getMessage()]);
    }
}

// Export schema structure and import to target schema
function exportImportSchema($pdo) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['source_schema']) || !isset($data['target_schema'])) {
            echo json_encode(['success' => false, 'message' => 'Source and target schemas are required']);
            return;
        }
        
        $sourceSchema = $data['source_schema'];
        $targetSchema = $data['target_schema'];
        
        // Get all tables from source schema
        $stmt = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'");
        $stmt->execute([$sourceSchema]);
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $createdTables = [];
        $createdProcedures = [];
        $createdFunctions = [];
        $createdTriggers = [];
        $errors = [];
        
        // Copy table structures
        foreach ($tables as $table) {
            try {
                // Get CREATE TABLE statement
                $stmt = $pdo->prepare("SHOW CREATE TABLE `{$sourceSchema}`.`{$table}`");
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $createStatement = $result['Create Table'];
                
                // Replace schema name
                $createStatement = str_replace("CREATE TABLE `{$table}`", "CREATE TABLE `{$targetSchema}`.`{$table}`", $createStatement);
                
                // Execute CREATE TABLE
                $pdo->exec($createStatement);
                $createdTables[] = $table;
            } catch (PDOException $e) {
                $errors[] = "Table {$table}: " . $e->getMessage();
            }
        }
        
        // Copy stored procedures with proper handling
        $stmt = $pdo->prepare("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_TYPE = 'PROCEDURE'");
        $stmt->execute([$sourceSchema]);
        $procedures = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($procedures as $procedure) {
            try {
                // Use information_schema to get the procedure definition
                $stmt = $pdo->prepare("SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_NAME = ? AND ROUTINE_TYPE = 'PROCEDURE'");
                $stmt->execute([$sourceSchema, $procedure]);
                $routineData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($routineData) {
                    // Get full CREATE statement
                    $showStmt = $pdo->prepare("SHOW CREATE PROCEDURE `{$sourceSchema}`.`{$procedure}`");
                    $showStmt->execute();
                    $result = $showStmt->fetch(PDO::FETCH_ASSOC);
                    $createStatement = $result['Create Procedure'];
                    
                    // Remove DEFINER clause completely
                    $createStatement = preg_replace("/DEFINER\s*=\s*`[^`]+`@`[^`]+`\s*/i", "", $createStatement);
                    
                    // Replace all schema references (both backticked and non-backticked)
                    $createStatement = str_replace("`{$sourceSchema}`.", "`{$targetSchema}`.", $createStatement);
                    $createStatement = str_replace("{$sourceSchema}.", "{$targetSchema}.", $createStatement);
                    
                    // Replace procedure name with schema prefix
                    $createStatement = preg_replace("/CREATE\s+PROCEDURE\s+`?{$procedure}`?/i", "CREATE PROCEDURE `{$targetSchema}`.`{$procedure}`", $createStatement);
                    
                    // Set SQL mode to handle procedure creation
                    $pdo->exec("SET sql_mode = ''");
                    
                    // Execute CREATE PROCEDURE
                    $pdo->exec($createStatement);
                    $createdProcedures[] = $procedure;
                }
            } catch (PDOException $e) {
                $errors[] = "Procedure {$procedure}: " . $e->getMessage();
            }
        }
        
        // Copy functions with proper handling
        $stmt = $pdo->prepare("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_TYPE = 'FUNCTION'");
        $stmt->execute([$sourceSchema]);
        $functions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($functions as $function) {
            try {
                // Get full CREATE statement
                $showStmt = $pdo->prepare("SHOW CREATE FUNCTION `{$sourceSchema}`.`{$function}`");
                $showStmt->execute();
                $result = $showStmt->fetch(PDO::FETCH_ASSOC);
                $createStatement = $result['Create Function'];
                
                // Remove DEFINER clause completely
                $createStatement = preg_replace("/DEFINER\s*=\s*`[^`]+`@`[^`]+`\s*/i", "", $createStatement);
                
                // Replace all schema references (both backticked and non-backticked)
                $createStatement = str_replace("`{$sourceSchema}`.", "`{$targetSchema}`.", $createStatement);
                $createStatement = str_replace("{$sourceSchema}.", "{$targetSchema}.", $createStatement);
                
                // Replace function name with schema prefix
                $createStatement = preg_replace("/CREATE\s+FUNCTION\s+`?{$function}`?/i", "CREATE FUNCTION `{$targetSchema}`.`{$function}`", $createStatement);
                
                // Set SQL mode to handle function creation
                $pdo->exec("SET sql_mode = ''");
                
                // Execute CREATE FUNCTION
                $pdo->exec($createStatement);
                $createdFunctions[] = $function;
            } catch (PDOException $e) {
                $errors[] = "Function {$function}: " . $e->getMessage();
            }
        }
        
        // Copy triggers - Note: Triggers must be created AFTER tables exist
        $stmt = $pdo->prepare("SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY EVENT_OBJECT_TABLE");
        $stmt->execute([$sourceSchema]);
        $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($triggers as $trigger) {
            try {
                // Check if the target table exists
                $tableCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
                $tableCheckStmt->execute([$targetSchema, $trigger['EVENT_OBJECT_TABLE']]);
                $tableExists = $tableCheckStmt->fetchColumn() > 0;
                
                if (!$tableExists) {
                    $errors[] = "Trigger {$trigger['TRIGGER_NAME']}: Target table {$trigger['EVENT_OBJECT_TABLE']} does not exist in {$targetSchema}";
                    continue;
                }
                
                // Get full CREATE statement
                $showStmt = $pdo->prepare("SHOW CREATE TRIGGER `{$sourceSchema}`.`{$trigger['TRIGGER_NAME']}`");
                $showStmt->execute();
                $result = $showStmt->fetch(PDO::FETCH_ASSOC);
                $createStatement = $result['SQL Original Statement'];
                
                // Remove DEFINER clause completely
                $createStatement = preg_replace("/DEFINER\s*=\s*`[^`]+`@`[^`]+`\s*/i", "", $createStatement);
                
                // Replace all schema references in trigger body
                $createStatement = str_replace("`{$sourceSchema}`.", "`{$targetSchema}`.", $createStatement);
                $createStatement = str_replace("{$sourceSchema}.", "{$targetSchema}.", $createStatement);
                
                // Update the table reference in CREATE TRIGGER statement
                $createStatement = preg_replace(
                    "/ON\s+`?{$sourceSchema}`?\.`?{$trigger['EVENT_OBJECT_TABLE']}`?/i",
                    "ON `{$targetSchema}`.`{$trigger['EVENT_OBJECT_TABLE']}`",
                    $createStatement
                );
                
                // Set SQL mode to handle trigger creation
                $pdo->exec("SET sql_mode = ''");
                
                // Execute CREATE TRIGGER
                $pdo->exec($createStatement);
                $createdTriggers[] = $trigger['TRIGGER_NAME'];
            } catch (PDOException $e) {
                $errors[] = "Trigger {$trigger['TRIGGER_NAME']}: " . $e->getMessage();
            }
        }
        
        // Update tenant provisioning status to indicate tables have been created
        if (count($errors) === 0 && count($createdTables) > 0) {
            // Update the tenant record to mark tables as created
            $updateStmt = $pdo->prepare("UPDATE tenant_master SET tables_imported = 1, updated_on = NOW() WHERE tenant_schema = ?");
            $updateStmt->execute([$targetSchema]);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Schema export/import completed',
            'details' => [
                'tables' => count($createdTables),
                'procedures' => count($createdProcedures),
                'functions' => count($createdFunctions),
                'triggers' => count($createdTriggers),
                'errors' => count($errors)
            ],
            'created_tables' => $createdTables,
            'created_procedures' => $createdProcedures,
            'created_functions' => $createdFunctions,
            'created_triggers' => $createdTriggers,
            'errors' => $errors
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to export/import schema: ' . $e->getMessage()]);
    }
}

// Provision database
function provisionDatabase($pdo) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['tenant_id'])) {
            echo json_encode(['success' => false, 'message' => 'Tenant ID is required']);
            return;
        }
        
        $tenantId = $data['tenant_id'];
        $inputType = 'PROVISION DB';
        
        // Call PR_SYNC_TENANT_MASTER
        $stmt = $pdo->prepare("CALL PR_SYNC_TENANT_MASTER(?, ?)");
        $stmt->execute([$tenantId, $inputType]);
        
        // Try to get the result from the procedure
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        
        // If the stored procedure returns a result with success/message fields, use it
        if ($result && isset($result['success'])) {
            echo json_encode($result);
        } 
        // If the stored procedure returns a result but no success field, assume success
        else if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Database provisioned successfully',
                'data' => $result
            ]);
        }
        // If no result returned, assume success (procedure completed without errors)
        else {
            // Verify the provisioning status was updated
            $checkStmt = $pdo->prepare("SELECT provisioning_status FROM tenant_master WHERE tenant_id = ?");
            $checkStmt->execute([$tenantId]);
            $status = $checkStmt->fetchColumn();
            
            if ($status === 'DB PROVISIONED' || $status === 'LIVE') {
                echo json_encode([
                    'success' => true,
                    'message' => 'Database provisioned successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'message' => 'Provision command executed. Current status: ' . $status
                ]);
            }
        }
        
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to provision database: ' . $e->getMessage()
        ]);
    }
}

// Go Live
function goLive($pdo) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['tenant_id'])) {
            echo json_encode(['success' => false, 'message' => 'Tenant ID is required']);
            return;
        }
        
        $tenantId = $data['tenant_id'];
        $inputType = 'GO LIVE';
        
        // Call PR_SYNC_TENANT_MASTER
        $stmt = $pdo->prepare("CALL PR_SYNC_TENANT_MASTER(?, ?)");
        $stmt->execute([$tenantId, $inputType]);
        
        // Try to get the result from the procedure
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        
        // If the stored procedure returns a result with success/message fields, use it
        if ($result && isset($result['success'])) {
            echo json_encode($result);
        } 
        // If the stored procedure returns a result but no success field, assume success
        else if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Tenant is now live!',
                'data' => $result
            ]);
        }
        // If no result returned, assume success (procedure completed without errors)
        else {
            // Verify the provisioning status was updated to LIVE
            $checkStmt = $pdo->prepare("SELECT provisioning_status FROM tenant_master WHERE tenant_id = ?");
            $checkStmt->execute([$tenantId]);
            $status = $checkStmt->fetchColumn();
            
            if ($status === 'LIVE') {
                echo json_encode([
                    'success' => true,
                    'message' => 'Tenant is now live!'
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'message' => 'Go Live command executed. Current status: ' . $status
                ]);
            }
        }
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to go live: ' . $e->getMessage()]);
    }
}

function deleteTenant($pdo) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['master_id'])) {
            echo json_encode(['success' => false, 'message' => 'Master ID is required']);
            return;
        }
        
        $stmt = $pdo->prepare("DELETE FROM tenant_master WHERE master_id = ?");
        $stmt->execute([$data['master_id']]);
        
        echo json_encode(['success' => true, 'message' => 'Tenant deleted successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to delete tenant: ' . $e->getMessage()]);
    }
}

// Decrypt password for display (when eye icon is clicked)
function decryptPassword($pdo) {
    global $ENCRYPTION_KEY;
    
    try {
        // Get raw input
        $rawInput = file_get_contents('php://input');
        error_log("Raw input: " . $rawInput);
        
        $data = json_decode($rawInput, true);
        
        if (!isset($data['master_id']) || empty($data['master_id'])) {
            error_log("Master ID missing. Data received: " . print_r($data, true));
            echo json_encode(['success' => false, 'message' => 'Master ID is required']);
            return;
        }
        
        // Get encrypted password from database
        $stmt = $pdo->prepare("SELECT admin_password FROM tenant_master WHERE master_id = ?");
        $stmt->execute([$data['master_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            echo json_encode(['success' => false, 'message' => 'Tenant not found']);
            return;
        }
        
        // Decrypt password
        list($iv, $encryptedPassword) = explode('::', $result['admin_password'], 2);
        $iv = base64_decode($iv);
        $plainPassword = openssl_decrypt($encryptedPassword, 'aes-256-cbc', $ENCRYPTION_KEY, 0, $iv);
        
        echo json_encode(['success' => true, 'password' => $plainPassword]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to decrypt password: ' . $e->getMessage()]);
    }
}
?>