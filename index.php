<?php
/**
 * PHPMyChroma - Web interface for ChromaDB vector database
 * Provides a phpMyAdmin-like interface for managing ChromaDB collections and documents
 */
session_start();

// --- INCLUDES & CONFIG ---
require_once 'include_chromadb.php';
require_once 'include_openai.php';

/**
 * Load environment variables from .env file
 * Simple parser that handles quoted values and ignores comments
 */
function load_env($path) {
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comment lines
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        // Remove quotes if present
        if (substr($value, 0, 1) == '"' && substr($value, -1) == '"') {
            $value = substr($value, 1, -1);
        }
        $_ENV[$name] = $value;
    }
}
load_env(__DIR__ . '/.env');

// --- Global Variables ---
$chromaClient = null;
$openAIClient = null;
$error = null;

/**
 * Check if ChromaDB server is responding by calling the health check endpoint
 * @param string $base_url The base URL of the ChromaDB server
 * @return bool True if server is responding, false otherwise
 */
function checkChromaDBHealth($base_url) {
    $health_url = rtrim($base_url, '/') . '/api/v2/healthcheck';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $health_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['accept: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        return isset($data['is_executor_ready']) && $data['is_executor_ready'] === true;
    }
    
    return false;
}

// --- Initialize Clients if session is set ---
// Only create clients if user has already connected to ChromaDB
if (isset($_SESSION['chroma_connection'])) {
    $conn = $_SESSION['chroma_connection'];
    try {
        // Initialize ChromaDB client with connection details from session
        $chromaClient = new ChromaDBClient(
            $conn['base_url'],
            $conn['api_key'],
            $conn['tenant'] ?? $_GET['tenant'] ?? 'default_tenant',
            $_GET['db'] ?? 'default_database'
        );
        // Initialize OpenAI client for embedding generation
        $openAIClient = new OpenAIClient($_ENV['OPENAI_API_KEY']);
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}


// --- ROUTING & ACTIONS ---
$action = $_REQUEST['action'] ?? 'login';

// --- POST Actions (Form Submissions) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($action) {
            case 'login':
                // First check if ChromaDB server is responding
                $base_url = rtrim($_POST['host'], '/') . ':' . $_POST['port'];
                
                if (!checkChromaDBHealth($base_url)) {
                    throw new Exception("ChromaDB server is not responding at $base_url. Please check the server is running and the URL is correct.");
                }
                
                // Validate tenant by trying to list databases
                $tenant = $_POST['tenant'];
                try {
                    $testClient = new ChromaDBClient($base_url, $_POST['api_key'], $tenant, 'default_database');
                    $testClient->listDatabases($tenant);
                } catch (Exception $e) {
                    throw new Exception("Invalid tenant '$tenant' or unable to access it: " . $e->getMessage());
                }
                
                // Store connection details in session
                $_SESSION['chroma_connection'] = [
                    'base_url' => $base_url,
                    'api_key' => $_POST['api_key'],
                    'tenant' => $tenant
                ];
                header("Location: index.php?action=list_databases&tenant=" . urlencode($tenant));
                exit();

            case 'create_collection':
                // Create new collection in current database
                $chromaClient->createCollection($_POST['collection_name']);
                header("Location: index.php?action=list_collections&tenant=".$_GET['tenant']."&db=".$_GET['db']);
                exit();

            case 'create_database':
                // Create new database in current tenant
                $chromaClient->createDatabase($_POST['database_name']);
                header("Location: index.php?action=list_databases&tenant=".$_GET['tenant']);
                exit();

            case 'add_document':
                // Add new document to collection with embedding
                $text = $_POST['document_text'];
                $id = $_POST['document_id'] ?: uniqid('doc-'); // Generate ID if not provided
                $metadata = json_decode($_POST['document_metadata'], true);
                if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Invalid JSON in metadata.");
                
                // Generate embedding using OpenAI API
                $embedding = $openAIClient->generateEmbedding($text);
                $chromaClient->addDocuments($_GET['collection'], [$text], [$embedding], [$metadata], [$id]);
                $pageParam = isset($_GET['page']) ? "&page=" . intval($_GET['page']) : "";
                header("Location: index.php?action=browse_collection&tenant=".$_GET['tenant']."&db=".$_GET['db']."&collection=".$_GET['collection'].$pageParam);
                exit();

             case 'edit_document':
                // Update existing document with new content and embedding
                $text = $_POST['document_text'];
                $id = $_POST['document_id'];
                $metadata = json_decode($_POST['document_metadata'], true);
                if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Invalid JSON in metadata.");

                // Regenerate embedding for updated text
                $embedding = $openAIClient->generateEmbedding($text);
                $chromaClient->updateDocuments($_GET['collection'], [$text], [$embedding], [$metadata], [$id]);
                $pageParam = isset($_GET['page']) ? "&page=" . intval($_GET['page']) : "";
                header("Location: index.php?action=browse_collection&tenant=".$_GET['tenant']."&db=".$_GET['db']."&collection=".$_GET['collection'].$pageParam);
                exit();
                
            case 'semantic_search':
                // Perform semantic search using query text embedding
                $queryText = $_POST['query_text'];
                $resultCount = intval($_POST['result_count']);
                
                // Generate embedding for search query
                $embedding = $openAIClient->generateEmbedding($queryText);
                $searchResults = $chromaClient->queryCollection($_GET['collection'], [$embedding], $resultCount);
                
                // Store search results in session to display them
                $_SESSION['search_results'] = [
                    'query' => $queryText,
                    'results' => $searchResults,
                    'count' => $resultCount
                ];
                
                header("Location: index.php?action=semantic_search&tenant=".$_GET['tenant']."&db=".$_GET['db']."&collection=".$_GET['collection']);
                exit();
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// --- GET Actions (Navigation and Deletions) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
         switch ($action) {
            case 'logout':
                // Destroy entire session
                session_destroy();
                header("Location: index.php");
                exit();

            case 'disconnect':
                // Remove connection details but keep session
                unset($_SESSION['chroma_connection']);
                header("Location: index.php");
                exit();

            case 'delete_collection':
                // Delete collection and return to collection list
                $chromaClient->deleteCollection($_GET['collection']);
                header("Location: index.php?action=list_collections&tenant=".$_GET['tenant']."&db=".$_GET['db']);
                exit();

            case 'delete_database':
                // Delete database and return to database list
                $chromaClient->deleteDatabase($_GET['db']);
                header("Location: index.php?action=list_databases&tenant=".$_GET['tenant']);
                exit();

            case 'delete_document':
                // Delete specific document from collection
                $chromaClient->deleteDocument($_GET['collection'], [$_GET['doc_id']]);
                $pageParam = isset($_GET['page']) ? "&page=" . intval($_GET['page']) : "";
                header("Location: index.php?action=browse_collection&tenant=".$_GET['tenant']."&db=".$_GET['db']."&collection=".$_GET['collection'].$pageParam);
                exit();
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}


// --- UI RENDERING FUNCTIONS ---
/**
 * Generate HTML page header with navigation breadcrumbs
 * @param string $title Page title to display
 */
function page_header($title) {
    global $_ENV;
    $tenant = htmlspecialchars($_GET['tenant'] ?? '');
    $db = htmlspecialchars($_GET['db'] ?? '');
    $collection = htmlspecialchars($_GET['collection'] ?? '');
    
    // Build breadcrumb navigation
    $breadcrumbs = '';
    
    if ($tenant) $breadcrumbs .= " &gt; <a href='index.php?action=list_databases&tenant=$tenant'>$tenant</a>";
    if ($db) $breadcrumbs .= " &gt; <a href='index.php?action=list_collections&tenant=$tenant&db=$db'>$db</a>";
    if ($collection) $breadcrumbs .= " &gt; $collection";
    
    // Show disconnect link if connected
    $disconnect_link = '';
    if (isset($_SESSION['chroma_connection'])) {
        $disconnect_link = '<a href="index.php?action=disconnect" style="color: #dc3545;">Disconnect</a>';
    }
    
    $max_width = $_ENV['MAX_WIDTH'] ?? '1000px';

    echo <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-g">
        <title>$title - PHPMyChroma</title>
        <style>
            body { font-family: sans-serif; font-size: 0.9em; line-height: 1.4; margin: 0; padding: 0; }
            .container { max-width: $max_width; margin: 0 auto; padding: 20px; }
            h1, h2, h3 { color: #333; }
            a { color: #007bff; text-decoration: none; }
            a:hover { text-decoration: underline; }
            table { border-collapse: collapse; width: 100%; margin-top: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top;}
            th { background-color: #f2f2f2; }
            tr:nth-child(even) { background-color: #f9f9f9; }
            .nav { background-color: #eee; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
            .error { background-color: #f8d7da; color: #721c24; padding: 10px; border: 1px solid #f5c6cb; border-radius: 5px; margin: 20px 0; }
            .form-container { background-color: #f2f2f2; padding: 20px; border-radius: 5px; margin-top: 20px; max-width: 600px; }
            .form-container input[type=text], .form-container input[type=password], .form-container textarea, .form-container input[type=number] { width: 95%; padding: 8px; margin-bottom: 10px; border-radius: 4px; border: 1px solid #ccc; }
            .form-container button { background-color: #007bff; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; }
            .form-container button:hover { background-color: #0056b3; }
            .actions a { margin-right: 10px; }
            .logout { float: right; }
            pre { background-color: #eee; padding: 10px; border-radius: 4px; white-space: pre-wrap; word-wrap: break-word; }
            .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
            .page-header h1 { margin: 0; }
            .btn-outline-secondary { 
                display: inline-block; 
                padding: 6px 12px; 
                margin-bottom: 0; 
                font-size: 14px; 
                font-weight: 400; 
                line-height: 1.42857143; 
                text-align: center; 
                white-space: nowrap; 
                vertical-align: middle; 
                cursor: pointer; 
                border: 1px solid #6c757d; 
                border-radius: 4px; 
                color: #6c757d; 
                background-color: transparent; 
                text-decoration: none; 
            }
            .btn-outline-secondary:hover { 
                color: #fff; 
                background-color: #6c757d; 
                border-color: #6c757d; 
                text-decoration: none; 
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="nav">
                PHPMyChroma
                <span class="logout">$disconnect_link v 0.2</span>
                <hr>
                $breadcrumbs
            </div>
            <h1>$title</h1>
HTML;
}

/**
 * Generate HTML page footer with error display and JavaScript
 */
function page_footer() {
    global $error;
    // Display error message if present
    if ($error) {
        echo "<div class='error'><strong>Error:</strong> " . htmlspecialchars($error) . "</div>";
    }
    echo <<<HTML
        </div>
        <script>
        // Toggle create form visibility
        function toggleCreateForm() {
            const form = document.getElementById('createForm');
            const button = document.getElementById('toggleForm');
            
            if (form.style.display === 'none' || form.style.display === '') {
                form.style.display = 'block';
                button.style.display = 'none';
            } else {
                form.style.display = 'none';
                button.style.display = 'block';
            }
        }
        </script>
    </body></html>
HTML;
}

// --- VIEW LOGIC ---
// Store current page for potential redirects
$_SESSION['HTTP_REFERER'] = $_SERVER['REQUEST_URI'];

// Route to appropriate view based on action
switch ($action) {
    

    case 'list_databases':
        // Display databases in current tenant with create/delete options
        page_header("");
        
        // Header with title and button on the same level
        echo '<div class="page-header">';
        echo '<h1>Databases for Tenant: ' . htmlspecialchars($_GET['tenant']) . '</h1>';
        echo '<button id="toggleForm" class="btn-outline-secondary" onclick="toggleCreateForm()">+ Create New Database</button>';
        echo '</div>';
        
        // Hidden form for creating new database
        echo '<div id="createForm" class="form-container" style="display: none;">';
        echo '<h3>Create New Database</h3>';
        echo '<form method="post" action="?action=create_database&tenant='.htmlspecialchars($_GET['tenant']).'">';
        echo '<label>Database Name:</label><br>';
        echo '<input type="text" name="database_name" autocomplete="off" required><br>';
        echo '<button type="submit">Create</button>';
        echo '<button type="button" onclick="toggleCreateForm()">Cancel</button>';
        echo '</form>';
        echo '</div>';
        
        $databases = $chromaClient->listDatabases($_GET['tenant']);
        echo "<table><tr><th>Database Name</th><th>Action</th></tr>";
        foreach ($databases as $db) {
            $name = htmlspecialchars($db['name']);
            echo "<tr><td>$name</td><td class='actions'>";
            echo "<a href='?action=list_collections&tenant=".htmlspecialchars($_GET['tenant'])."&db=$name'>Select</a>";
            echo "<a href='?action=delete_database&tenant=".htmlspecialchars($_GET['tenant'])."&db=$name' onclick='return confirm(\"Are you sure you want to delete database: $name?\")'>Delete</a>";
            echo "</td></tr>";
        }
        echo "</table>";
        break;

    case 'list_collections':
        // Display collections in current database with document counts
        page_header("");
        
        // Header with title and button on the same level
        echo '<div class="page-header">';
        echo '<h1>Collections</h1>';
        echo '<button id="toggleForm" class="btn-outline-secondary" onclick="toggleCreateForm()">+ Create New Collection</button>';
        echo '</div>';
        
        // Hidden form for creating new collection
        echo '<div id="createForm" class="form-container" style="display: none;">';
        echo '<h3>Create New Collection</h3>';
        echo '<form method="post" action="?action=create_collection&tenant='.htmlspecialchars($_GET['tenant']).'&db='.htmlspecialchars($_GET['db']).'">';
        echo '<label>Collection Name:</label><br>';
        echo '<input type="text" name="collection_name" autocomplete="off" required><br>';
        echo '<button type="submit">Create</button>';
        echo '<button type="button" onclick="toggleCreateForm()">Cancel</button>';
        echo '</form>';
        echo '</div>';
        
        $collections = $chromaClient->listCollections();
        echo "<table><thead><tr><th>Name</th><th>ID</th><th>Documents</th><th>Metadata</th><th>Actions</th></tr></thead><tbody>";
        if (!empty($collections)) {
            foreach ($collections as $collection) {
                try {
                    // Get document count for each collection
                    $documentCount = $chromaClient->countDocuments($collection['name']);
                } catch (Exception $e) {
                    $documentCount = 'Error';
                }
                
                echo "<tr>";
                echo "<td>" . htmlspecialchars($collection['name']) . "</td>";
                echo "<td>" . htmlspecialchars($collection['id']) . "</td>";
                echo "<td>" . $documentCount . "</td>";
                echo "<td><pre>" . htmlspecialchars(json_encode($collection['metadata'], JSON_PRETTY_PRINT)) . "</pre></td>";
                echo "<td class='actions'>";
                echo "<a href='?action=browse_collection&tenant=".htmlspecialchars($_GET['tenant'])."&db=".htmlspecialchars($_GET['db'])."&collection=" . htmlspecialchars($collection['name']) . "'>Browse</a>";
                echo "<a href='?action=semantic_search&tenant=".htmlspecialchars($_GET['tenant'])."&db=".htmlspecialchars($_GET['db'])."&collection=" . htmlspecialchars($collection['name']) . "'>Search</a>";
                echo "<a href='?action=delete_collection&tenant=".htmlspecialchars($_GET['tenant'])."&db=".htmlspecialchars($_GET['db'])."&collection=" . htmlspecialchars($collection['name']) . "' onclick='return confirm(\"Are you sure?\")'>Delete</a>";
                echo "</td>";
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='5'>No collections found.</td></tr>";
        }
        echo "</tbody></table>";
        break;

    case 'browse_collection':
        // Browse documents in collection with pagination
        page_header("Browse Collection: " . htmlspecialchars($_GET['collection']));
        
        // Pagination parameters
        $limit = 50;
        $page = max(1, intval($_GET['page'] ?? 1));
        $offset = ($page - 1) * $limit;
        
        $count = $chromaClient->countDocuments($_GET['collection']);
        $totalPages = ceil($count / $limit);
        
        echo "<p>Total documents: {$count} | Page {$page} of {$totalPages}</p>";
        echo "<div class='actions'>";
        echo "<a href='?action=add_document&tenant=".htmlspecialchars($_GET['tenant'])."&db=".htmlspecialchars($_GET['db'])."&collection=".htmlspecialchars($_GET['collection'])."&page={$page}'>+ Add New Document</a>";
        echo "<a href='?action=semantic_search&tenant=".htmlspecialchars($_GET['tenant'])."&db=".htmlspecialchars($_GET['db'])."&collection=".htmlspecialchars($_GET['collection'])."'>🔍 Search Collection</a>";
        echo "</div>";
        
        $documentsResult = $chromaClient->getDocuments($_GET['collection'], [], $limit, $offset);
        
        // Debug: Let's see what we're getting
        // echo "<pre>Debug: " . print_r($documentsResult, true) . "</pre>";
        
        echo "<table><thead><tr><th>ID</th><th>Document</th><th>Metadata</th><th>Actions</th></tr></thead><tbody>";
        if (!empty($documentsResult['ids'])) {
            for ($i = 0; $i < count($documentsResult['ids']); $i++) {
                $id = htmlspecialchars($documentsResult['ids'][$i]);
                $doc = htmlspecialchars($documentsResult['documents'][$i]);
                $meta = htmlspecialchars(json_encode($documentsResult['metadatas'][$i], JSON_PRETTY_PRINT));
                
                // Get limits from environment variables for display truncation
                $doc_limit = intval($_ENV['listview_doc_limit'] ?? 1024);
                $meta_limit = intval($_ENV['listview_metadata_limit'] ?? 1024);
                
                // Truncate document text if it exceeds limit
                if (strlen($doc) > $doc_limit) {
                    $doc = substr($doc, 0, $doc_limit) . '...';
                }
                
                // Truncate metadata if it exceeds limit
                if (strlen($meta) > $meta_limit) {
                    $meta = substr($meta, 0, $meta_limit) . '...';
                }
                
                echo "<tr>";
                echo "<td>{$id}</td>";
                echo "<td><pre>{$doc}</pre></td>";
                echo "<td><pre>{$meta}</pre></td>";
                echo "<td class='actions'>";
                echo "<a href='?action=edit_document&tenant=".htmlspecialchars($_GET['tenant'])."&db=".htmlspecialchars($_GET['db'])."&collection=".htmlspecialchars($_GET['collection'])."&doc_id={$id}&page={$page}'>Edit</a>";
                echo "<a href='?action=delete_document&tenant=".htmlspecialchars($_GET['tenant'])."&db=".htmlspecialchars($_GET['db'])."&collection=".htmlspecialchars($_GET['collection'])."&doc_id={$id}&page={$page}' onclick='return confirm(\"Are you sure?\")'>Delete</a>";
                echo "</td>";
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='4'>No documents found in this collection.</td></tr>";
        }
        echo "</tbody></table>";
        
        // Pagination navigation
        if ($totalPages > 1) {
            echo "<div style='margin-top: 20px; text-align: center;'>";
            
            // Previous button
            if ($page > 1) {
                $prevPage = $page - 1;
                echo "<a href='?action=browse_collection&tenant=".htmlspecialchars($_GET['tenant'])."&db=".htmlspecialchars($_GET['db'])."&collection=".htmlspecialchars($_GET['collection'])."&page={$prevPage}' style='margin-right: 10px;'>« Previous</a>";
            }
            
            // Page numbers (show current page +/- 2)
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            
            for ($i = $startPage; $i <= $endPage; $i++) {
                if ($i == $page) {
                    echo "<strong style='margin: 0 5px;'>{$i}</strong>";
                } else {
                    echo "<a href='?action=browse_collection&tenant=".htmlspecialchars($_GET['tenant'])."&db=".htmlspecialchars($_GET['db'])."&collection=".htmlspecialchars($_GET['collection'])."&page={$i}' style='margin: 0 5px;'>{$i}</a>";
                }
            }
            
            // Next button
            if ($page < $totalPages) {
                $nextPage = $page + 1;
                echo "<a href='?action=browse_collection&tenant=".htmlspecialchars($_GET['tenant'])."&db=".htmlspecialchars($_GET['db'])."&collection=".htmlspecialchars($_GET['collection'])."&page={$nextPage}' style='margin-left: 10px;'>Next »</a>";
            }
            
            echo "</div>";
        }
        break;

    case 'add_document':
        // Form for adding new document to collection
        page_header("Add Document to: " . htmlspecialchars($_GET['collection']));
        ?>
        <div class="form-container">
            <form method="post" action="?action=add_document&tenant=<?= htmlspecialchars($_GET['tenant']) ?>&db=<?= htmlspecialchars($_GET['db']) ?>&collection=<?= htmlspecialchars($_GET['collection']) ?>">
                <label for="document_id">Document ID (optional, will be auto-generated if blank):</label><br>
                <input type="text" id="document_id" name="document_id" autocomplete="off"><br>

                <label for="document_text">Document Text:</label><br>
                <textarea id="document_text" name="document_text" rows="10" autocomplete="off" required></textarea><br>
                
                <label for="document_metadata">Metadata (as JSON):</label><br>
                <textarea id="document_metadata" name="document_metadata" rows="5" autocomplete="off">{}</textarea><br>

                <button type="submit">Add Document</button>
            </form>
        </div>
        <?php
        break;
    
    case 'edit_document':
        // Form for editing existing document
        page_header("Edit Document in: " . htmlspecialchars($_GET['collection']));
        $doc = $chromaClient->getDocuments($_GET['collection'], [$_GET['doc_id']])
        ?>
        <div class="form-container">
             <form method="post" action="?action=edit_document&tenant=<?= htmlspecialchars($_GET['tenant']) ?>&db=<?= htmlspecialchars($_GET['db']) ?>&collection=<?= htmlspecialchars($_GET['collection']) ?>">
                <input type="hidden" name="document_id" value="<?= htmlspecialchars($doc['ids'][0]) ?>">

                <label>Document ID:</label><br>
                <input type="text" value="<?= htmlspecialchars($doc['ids'][0]) ?>" disabled><br>

                <label for="document_text">Document Text:</label><br>
                <textarea id="document_text" name="document_text" rows="10" autocomplete="off" required><?= htmlspecialchars($doc['documents'][0]) ?></textarea><br>
                
                <label for="document_metadata">Metadata (as JSON):</label><br>
                <textarea id="document_metadata" name="document_metadata" rows="5" autocomplete="off"><?= htmlspecialchars(json_encode($doc['metadatas'][0], JSON_PRETTY_PRINT)) ?></textarea><br>

                <button type="submit">Update Document</button>
            </form>
        </div>
        <?php
        break;
        
    case 'semantic_search':
        // Semantic search interface and results display
        page_header("Semantic Search: " . htmlspecialchars($_GET['collection']));
        ?>
        <div class="form-container">
            <form method="post" action="?action=semantic_search&tenant=<?= htmlspecialchars($_GET['tenant']) ?>&db=<?= htmlspecialchars($_GET['db']) ?>&collection=<?= htmlspecialchars($_GET['collection']) ?>">
                <label for="query_text">Search Query:</label><br>
                <textarea id="query_text" name="query_text" rows="3" autocomplete="off" required placeholder="Enter your search query..."><?= isset($_SESSION['search_results']) ? htmlspecialchars($_SESSION['search_results']['query']) : '' ?></textarea><br>
                
                <label for="result_count">Number of Results:</label><br>
                <select id="result_count" name="result_count">
                    <option value="1" <?= (isset($_SESSION['search_results']) && $_SESSION['search_results']['count'] == 1) ? 'selected' : '' ?>>1</option>
                    <option value="5" <?= (isset($_SESSION['search_results']) && $_SESSION['search_results']['count'] == 5) ? 'selected' : 'selected' ?>>5</option>
                    <option value="10" <?= (isset($_SESSION['search_results']) && $_SESSION['search_results']['count'] == 10) ? 'selected' : '' ?>>10</option>
                    <option value="20" <?= (isset($_SESSION['search_results']) && $_SESSION['search_results']['count'] == 20) ? 'selected' : '' ?>>20</option>
                </select><br>
                
                <button type="submit">Search</button>
                <a href="?action=list_collections&tenant=<?= htmlspecialchars($_GET['tenant']) ?>&db=<?= htmlspecialchars($_GET['db']) ?>">Back to Collections</a>
            </form>
        </div>
        
        <?php
        // Display search results if available
        if (isset($_SESSION['search_results']) && !empty($_SESSION['search_results']['results'])) {
            $searchResults = $_SESSION['search_results']['results'];
            $queryText = $_SESSION['search_results']['query'];
            
            echo "<h2>Search Results for: \"" . htmlspecialchars($queryText) . "\"</h2>";
            echo "<table><thead><tr><th>Similarity Score</th><th>Document ID</th><th>Document Text</th><th>Metadata</th></tr></thead><tbody>";
            
            if (!empty($searchResults['ids']) && !empty($searchResults['ids'][0])) {
                for ($i = 0; $i < count($searchResults['ids'][0]); $i++) {
                    $distance = $searchResults['distances'][0][$i];
                    $similarity = round((1 - $distance) * 100, 2); // Convert distance to similarity percentage
                    $id = htmlspecialchars($searchResults['ids'][0][$i]);
                    $document = htmlspecialchars($searchResults['documents'][0][$i]);
                    $metadata = htmlspecialchars(json_encode($searchResults['metadatas'][0][$i], JSON_PRETTY_PRINT));
                    
                    echo "<tr>";
                    echo "<td>{$similarity}%</td>";
                    echo "<td>{$id}</td>";
                    echo "<td><pre style='max-width: 400px; white-space: pre-wrap;'>{$document}</pre></td>";
                    echo "<td><pre>{$metadata}</pre></td>";
                    echo "</tr>";
                }
            } else {
                echo "<tr><td colspan='4'>No results found for your search query.</td></tr>";
            }
            
            echo "</tbody></table>";
            
            // Clear search results after displaying to prevent stale data
            unset($_SESSION['search_results']);
        }
        ?>
        
        <?php
        break;


    default: // Login form
        // Initial connection form for ChromaDB
        page_header("Connect to ChromaDB");
        ?>
        <div class="form-container">
            <form method="post" action="?action=login">
                <label for="host">Host / IP:</label><br>
                <input type="text" id="host" name="host" value="http://localhost" autocomplete="off" required><br>
                
                <label for="port">Port:</label><br>
                <input type="number" id="port" name="port" value="8000" autocomplete="off" required><br>
                
                <label for="tenant">Tenant Name:</label><br>
                <input type="text" id="tenant" name="tenant" value="default_tenant" autocomplete="off" required><br>
                
                <label for="api_key">API Key (optional):</label><br>
                <input type="password" id="api_key" name="api_key" autocomplete="off"><br>
                
                <button type="submit">Connect</button>
            </form>
        </div>
        <?php
        break;
}

page_footer();
