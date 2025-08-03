<?php

/**
 * ChromaDB PHP Client for vector database operations
 * Provides methods to interact with ChromaDB tenants, databases, collections, and documents
 */
class ChromaDBClient {
    private $baseUrl;
    private $apiKey;
    private $tenant;
    private $database;
    // Cache collection IDs to avoid repeated lookups
    private $collectionCache = [];

    /**
     * Initialize ChromaDB client with connection parameters
     * @param string $baseUrl ChromaDB server URL
     * @param string|null $apiKey Optional API key for authentication
     * @param string $tenant Tenant name (multi-tenancy support)
     * @param string $database Database name within the tenant
     */
    public function __construct($baseUrl = 'http://localhost:8000', $apiKey = null, $tenant = 'default_tenant', $database = 'default_database') {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->tenant = $tenant;
        $this->database = $database;
    }

    
    /**
     * Make HTTP request to ChromaDB API at database level
     * @param string $endpoint API endpoint path
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param array|null $data Request payload data
     * @return array Decoded JSON response
     */
    private function makeRequest($endpoint, $method = 'GET', $data = null) {
        $url = $this->baseUrl . "/api/v2/tenants/{$this->tenant}/databases/{$this->database}" . $endpoint;
        
        // Debug: Log the full URL and method
        error_log("Making $method request to: $url");
        if ($data) {
            error_log("Request data: " . json_encode($data));
        }
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                $this->apiKey ? 'Authorization: Bearer ' . $this->apiKey : ''
            ]
        ]);
        
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        error_log("Response code: $httpCode, Response: $response");
        
        if ($httpCode >= 400) {
            throw new Exception("ChromaDB API Error: {$url} " . $response);
        }
        
        return json_decode($response, true);
    }

    /**
     * Make HTTP request to ChromaDB API at tenant level (for database operations)
     * @param string $endpoint API endpoint path
     * @param string $method HTTP method
     * @param array|null $data Request payload data
     * @return array Decoded JSON response
     */
    private function makeRequestAtTenantLevel($endpoint, $method = 'GET', $data = null) {
        $url = $this->baseUrl . "/api/v2/tenants/{$this->tenant}/" . $endpoint;
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                $this->apiKey ? 'Authorization: Bearer ' . $this->apiKey : ''
            ]
        ]);
        
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception("ChromaDB API Error: {$url} " . $response);
        }

        return json_decode($response, true);
    }


    /**
     * List all databases within a tenant
     * @param string $tenantName Name of the tenant
     * @return array List of database objects
     */
    public function listDatabases($tenantName) {
        $response = $this->makeRequestAtTenantLevel( 'databases', 'GET', [] );
        return $response;
    }

    /**
     * Create a new database within the current tenant
     * @param string $databaseName Name for the new database
     * @return array Response from ChromaDB API
     */
    public function createDatabase($databaseName) {
        $response = $this->makeRequestAtTenantLevel( 'databases', 'POST', ['name' => $databaseName] );
        return $response;
    }

    /**
     * Delete a database from the current tenant
     * @param string $databaseName Name of database to delete
     * @return array Response from ChromaDB API
     */
    public function deleteDatabase($databaseName) {
        $response = $this->makeRequestAtTenantLevel( "databases/{$databaseName}", 'DELETE' );
        return $response;
    }
    
    /**
     * Get collection ID by name with caching for performance
     * @param string $name Collection name
     * @param bool $forceRefresh Force refresh of cache
     * @return string Collection ID
     */
    private function getCollectionId($name, $forceRefresh = false) {
        if (!$forceRefresh && isset($this->collectionCache[$name])) {
            return $this->collectionCache[$name];
        }
        
        $collections = $this->listCollections();
        foreach ($collections as $collection) {
            if ($collection['name'] === $name) {
                $this->collectionCache[$name] = $collection['id'];
                return $collection['id'];
            }
        }
        throw new Exception("Collection not found: " . $name . " (Available collections: " . implode(', ', array_column($collections, 'name')) . ")");
    }
    
    /**
     * List all collections in the current database
     * @return array List of collection objects with metadata
     */
    public function listCollections() {
        return $this->makeRequest("/collections?database_name={$this->database}&tenant_name={$this->tenant}");
    }
    
    /**
     * Create a new collection with optional metadata
     * @param string $name Collection name
     * @param array|null $metadata Optional metadata for the collection
     * @return array Response from ChromaDB API
     */
    public function createCollection($name, $metadata = null) {
        $payload = ['name' => $name];
        
        // Only include metadata if it's not empty
        if ($metadata && !empty($metadata)) {
            $payload['metadata'] = $metadata;
        }
        
        $response = $this->makeRequest('/collections', 'POST', $payload);

        if (isset($response['id'])) {
            $this->collectionCache[$name] = $response['id'];
        }
        return $response;
    }
    
    /**
     * Delete a collection by name
     * @param string $name Collection name to delete
     * @return array Response from ChromaDB API
     */
    public function deleteCollection($name) {
        // Try deleting by collection name instead of ID
        $deleteUrl = "/collections/{$name}";
        
        // Debug: Log the details
        error_log("Attempting to delete collection: $name");
        error_log("Tenant: {$this->tenant}, Database: {$this->database}");
        error_log("Delete URL: $deleteUrl");
        
        $result = $this->makeRequest($deleteUrl, 'DELETE');
        
        // Clear from cache after successful deletion
        unset($this->collectionCache[$name]);
        
        return $result;
    }
    
    /**
     * Get collection details by name
     * @param string $name Collection name
     * @return array Collection object with metadata
     */
    public function getCollection($name) {
         $collectionId = $this->getCollectionId($name);
        return $this->makeRequest("/collections/{$collectionId}?tenant_name={$this->tenant}&database_name={$this->database}");
    }
    
    /**
     * Add documents to a collection with their embeddings
     * @param string $collectionName Target collection name
     * @param array $documents Array of document texts
     * @param array $embeddings Array of embedding vectors
     * @param array $metadatas Array of metadata objects (optional)
     * @param array $ids Array of document IDs (optional)
     * @return array Response from ChromaDB API
     */
    public function addDocuments($collectionName, $documents, $embeddings, $metadatas = [], $ids = []) {
        $collectionId = $this->getCollectionId($collectionName);
        return $this->makeRequest('/collections/' . $collectionId . '/add', 'POST', [
            'documents' => $documents,
            'embeddings' => $embeddings,
            'metadatas' => $metadatas,
            'ids' => $ids
        ]);
    }
    
    /**
     * Update existing documents in a collection
     * @param string $collectionName Target collection name
     * @param array $documents Array of updated document texts
     * @param array $embeddings Array of updated embedding vectors
     * @param array $metadatas Array of updated metadata objects (optional)
     * @param array $ids Array of document IDs to update
     * @return array Response from ChromaDB API
     */
    public function updateDocuments($collectionName, $documents, $embeddings, $metadatas = [], $ids = []) {
        $collectionId = $this->getCollectionId($collectionName);
        return $this->makeRequest( "/collections/{$collectionId}/update" , 'POST' , [
            'documents' => $documents , 
            'embeddings' => $embeddings , 
            'metadatas' => $metadatas , 
            'ids' => $ids 
        ]);
    }

    /**
     * Query collection for similar documents using embedding vectors
     * @param string $collectionName Target collection name
     * @param array $queryEmbeddings Array of query embedding vectors
     * @param int $nResults Number of results to return
     * @param array $where Optional metadata filter conditions
     * @return array Search results with similarities and metadata
     */
    public function queryCollection($collectionName, $queryEmbeddings, $nResults = 5, $where = []) {
        $collectionId = $this->getCollectionId($collectionName);
        $payload = [
            'query_embeddings' => $queryEmbeddings,
            'n_results' => $nResults
        ];
        
        // Only include where clause if it's not empty
        if (!empty($where)) {
            $payload['where'] = (object)$where;
        }
        
        return $this->makeRequest('/collections/' . $collectionId . '/query', 'POST', $payload);
    }
    
    /**
     * Retrieve documents from a collection with pagination
     * @param string $collectionName Target collection name
     * @param array $ids Optional array of specific document IDs to retrieve
     * @param int $limit Maximum number of documents to return
     * @param int $offset Number of documents to skip (for pagination)
     * @return array Documents with their metadata and embeddings
     */
    public function getDocuments($collectionName, $ids = [], $limit = 100, $offset = 0) {
        $collectionId = $this->getCollectionId($collectionName);
        $payload = ['limit' => $limit];
        
        // Only include ids if the array is not empty
        if (!empty($ids)) {
            $payload['ids'] = $ids;
        }
        
        // Add offset if provided
        if ($offset > 0) {
            $payload['offset'] = $offset;
        }
        
        return $this->makeRequest('/collections/' . $collectionId . '/get', 'POST', $payload);
    }

    /**
     * Count total number of documents in a collection
     * @param string $collectionName Target collection name
     * @return int Number of documents in the collection
     */
    public function countDocuments($collectionName) {
        $collectionId = $this->getCollectionId($collectionName);
        return $this->makeRequest('/collections/' . $collectionId . '/count');
    }

    /**
     * Delete specific documents from a collection
     * @param string $collectionName Target collection name
     * @param array $documentIds Array of document IDs to delete
     * @return array Response from ChromaDB API
     */
    public function deleteDocument($collectionName, $documentIds) {
        $collectionId = $this->getCollectionId($collectionName);
        return $this->makeRequest('/collections/' . $collectionId . '/delete', 'POST', [
            'ids' => $documentIds
        ]);
    }
}