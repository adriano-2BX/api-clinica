<?php
/*
 * =================================================================================================
 * API Completa em PHP para o Sistema de Gestão da Clínica (Arquivo Único)
 * =================================================================================================
 *
 * Descrição:
 * Este arquivo único contém toda a lógica da API para interagir com o banco de dados da clínica.
 * Ele inclui a configuração, conexão com o banco, handlers para operações CRUD genéricas e
 * a lógica de negócio complexa para consulta de horários disponíveis.
 *
 * --- INSTRUÇÕES DE CONFIGURAÇÃO DO SERVIDOR ---
 *
 * Para que as URLs amigáveis (ex: /api/pacientes) funcionem, o seu servidor web precisa
 * redirecionar todas as requisições para este arquivo.
 *
 * -------------------------------------------------------------------------------------------------
 * CONFIGURAÇÃO PARA NGINX (Causa do erro 404 Not Found)
 * -------------------------------------------------------------------------------------------------
 * Se você está usando Nginx, o arquivo .htaccess não funcionará. Você precisa editar o arquivo
 * de configuração do seu site (geralmente em /etc/nginx/sites-available/seu_dominio).
 * Dentro do bloco `server { ... }`, adicione ou modifique o bloco `location /` para:
 *
 * server {
 * # ... outras configurações como server_name e root ...
 * root /caminho/para/sua/api;
 *
 * location / {
 * try_files $uri $uri/ /api.php?$query_string;
 * }
 *
 * location ~ \.php$ {
 * include snippets/fastcgi-php.conf;
 * fastcgi_pass unix:/var/run/php/php-fpm.sock; // Verifique o caminho do seu socket PHP-FPM
 * }
 * }
 *
 * Após salvar a alteração, reinicie o Nginx: `sudo systemctl restart nginx`
 *
 * -------------------------------------------------------------------------------------------------
 * Conteúdo para o arquivo .htaccess (APENAS PARA SERVIDORES APACHE):
 * -------------------------------------------------------------------------------------------------
 * <IfModule mod_rewrite.c>
 * RewriteEngine On
 * RewriteCond %{REQUEST_FILENAME} !-f
 * RewriteCond %{REQUEST_FILENAME} !-d
 * RewriteRule ^(.*)$ api.php?path=$1 [QSA,L]
 * </IfModule>
 * -------------------------------------------------------------------------------------------------
 *
 * =================================================================================================
 */


// =================================================================================================
// SEÇÃO 1: CONFIGURAÇÃO E HEADERS
// =================================================================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Tratamento para requisições OPTIONS (pre-flight) do CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Definição das credenciais do banco de dados
define('DB_HOST', '69.62.93.202');
define('DB_NAME', 'doctorbot');
define('DB_USER', 'root');
define('DB_PASS', '9ac55e0e489bc5d43bbc');
define('DB_PORT', '3306');
define('DB_CHARSET', 'utf8mb4');


// =================================================================================================
// SEÇÃO 2: CLASSES DE LÓGICA (DATABASE E HANDLERS)
// =================================================================================================

/**
 * Classe Database (Singleton)
 * Gerencia a conexão PDO com o banco de dados para garantir que exista apenas uma instância.
 */
class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage(), (int)$e->getCode());
        }
    }

    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }
}

/**
 * Classe GenericHandler
 * Lida com as operações CRUD (Criar, Ler, Atualizar, Deletar) para qualquer tabela.
 */
class GenericHandler {
    private $pdo;
    private $table;

    public function __construct($pdo, $table) {
        $this->pdo = $pdo;
        // Sanitiza o nome da tabela para prevenir SQL Injection básico
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new Exception("Nome de tabela inválido.", 400);
        }
        $this->table = $table;
    }

    public function handleGet($id = null) {
        if ($id) {
            $stmt = $this->pdo->prepare("SELECT * FROM `{$this->table}` WHERE id = ?");
            $stmt->execute([$id]);
            $result = $stmt->fetch();
            if (!$result) throw new Exception("Registro não encontrado.", 404);
            return $result;
        } else {
            $stmt = $this->pdo->query("SELECT * FROM `{$this->table}`");
            return $stmt->fetchAll();
        }
    }

    public function handlePost($data) {
        if (empty($data)) throw new Exception("Dados não podem ser vazios.", 400);
        $columns = implode(', ', array_map(fn($col) => "`$col`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO `{$this->table}` ($columns) VALUES ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));
        return ['message' => 'Registro criado com sucesso.', 'id' => $this->pdo->lastInsertId()];
    }

    public function handlePut($id, $data) {
        if (empty($data)) throw new Exception("Dados não podem ser vazios.", 400);
        $fields = implode(', ', array_map(fn($col) => "`$col` = ?", array_keys($data)));
        $sql = "UPDATE `{$this->table}` SET $fields WHERE id = ?";
        $values = array_values($data);
        $values[] = $id;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        if ($stmt->rowCount() === 0) throw new Exception("Nenhum registro encontrado ou alterado para o ID fornecido.", 404);
        return ['message' => 'Registro atualizado com sucesso.', 'updated_rows' => $stmt->rowCount()];
    }

    public function handleDelete($id) {
        $sql = "DELETE FROM `{$this->table}` WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) throw new Exception("Nenhum registro encontrado para deletar com o ID fornecido.", 404);
        return ['message' => 'Registro deletado com sucesso.', 'deleted_rows' => $stmt->rowCount()];
    }
}

/**
 * Classe AgendaHandler
 * Lida com a lógica de negócio complexa para encontrar horários disponíveis.
 */
class AgendaHandler {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getAvailableSlots($params) {
        // Validação dos parâmetros
        if (!isset($params['recurso_id']) || !isset($params['data'])) {
            throw new Exception("Parâmetros 'recurso_id' e 'data' são obrigatórios.", 400);
        }
        $recurso_id = $params['recurso_id'];
        $data_str = $params['data'];
        $dia_semana = date('N', strtotime($data_str)) % 7 + 1; // 1=Dom, ..., 7=Sáb

        // 1. Buscar regras de disponibilidade padrão
        $stmt_regras = $this->pdo->prepare("SELECT * FROM regras_disponibilidade WHERE recurso_id = ? AND dia_semana = ?");
        $stmt_regras->execute([$recurso_id, $dia_semana]);
        $regras_disponibilidade = $stmt_regras->fetchAll();
        if (empty($regras_disponibilidade)) return [];

        // 2. Buscar agendamentos existentes
        $stmt_agendados = $this->pdo->prepare("SELECT data_hora_inicio FROM agendamentos WHERE recurso_id = ? AND DATE(data_hora_inicio) = ? AND status_agendamento NOT IN ('CANCELADO_PACIENTE', 'CANCELADO_CLINICA')");
        $stmt_agendados->execute([$recurso_id, $data_str]);
        $slots_ocupados = $stmt_agendados->fetchAll(PDO::FETCH_COLUMN, 0);
        $slots_ocupados = array_map(fn($dt) => date('H:i:s', strtotime($dt)), $slots_ocupados);

        // 3. Gerar todos os slots possíveis e remover os ocupados
        $slots_disponiveis = [];
        foreach ($regras_disponibilidade as $regra) {
            $inicio = new DateTime($data_str . ' ' . $regra['hora_inicio']);
            $fim = new DateTime($data_str . ' ' . $regra['hora_fim']);
            $intervalo = new DateInterval('PT' . $regra['duracao_slot_minutos'] . 'M');
            $periodo = new DatePeriod($inicio, $intervalo, $fim);
            foreach ($periodo as $slot) {
                if (!in_array($slot->format('H:i:s'), $slots_ocupados)) {
                    $slots_disponiveis[] = $slot;
                }
            }
        }
        
        // 4. Aplicar regras de restrição (lógica avançada)
        $stmt_restricoes = $this->pdo->prepare("SELECT * FROM regras_restricao_agenda WHERE recurso_id = ?");
        $stmt_restricoes->execute([$recurso_id]);
        $regras_restricao = $stmt_restricoes->fetchAll();

        if (!empty($regras_restricao)) {
            $procedimento_id = $params['procedimento_id'] ?? null;
            $convenio_id = $params['convenio_id'] ?? null;
            $procedimento = null;
            if ($procedimento_id) {
                 $stmt_proc = $this->pdo->prepare("SELECT * FROM procedimentos WHERE id = ?");
                 $stmt_proc->execute([$procedimento_id]);
                 $procedimento = $stmt_proc->fetch();
            }

            $slots_filtrados = array_filter($slots_disponiveis, function($slot) use ($regras_restricao, $convenio_id, $procedimento) {
                foreach ($regras_restricao as $restricao) {
                    $hora_slot = $slot->format('H:i:s');
                    $hora_inicio_regra = $restricao['hora_inicio'] ?? '00:00:00';
                    $hora_fim_regra = $restricao['hora_fim'] ?? '23:59:59';
                    
                    if ($hora_slot >= $hora_inicio_regra && $hora_slot < $hora_fim_regra) {
                        switch ($restricao['tipo_restricao']) {
                            case 'PERMITE_APENAS_CONVENIO':
                                if ($convenio_id != $restricao['id_referencia']) return false;
                                break;
                            case 'RESTRINGE_CONVENIO':
                                if ($convenio_id == $restricao['id_referencia']) return false;
                                break;
                            case 'SOMENTE_COM_CONTRASTE':
                                if (!$procedimento || !$procedimento['requer_contraste']) return false;
                                break;
                            case 'SOMENTE_SEM_CONTRASTE':
                                if ($procedimento && $procedimento['requer_contraste']) return false;
                                break;
                        }
                    }
                }
                return true;
            });
            $slots_disponiveis = array_values($slots_filtrados);
        }
        
        return array_map(fn($dt) => $dt->format('H:i'), array_unique($slots_disponiveis));
    }
}


// =================================================================================================
// SEÇÃO 3: ROTEADOR PRINCIPAL E EXECUÇÃO
// =================================================================================================

try {
    $pdo = Database::getInstance()->getConnection();
    
    // Análise da URL para roteamento
    $path = isset($_GET['path']) ? $_GET['path'] : '';
    $parts = explode('/', rtrim($path, '/'));
    
    $resource = $parts[0] ?? null;
    $id = filter_var($parts[1] ?? null, FILTER_VALIDATE_INT);

    if (!$resource) {
        http_response_code(200);
        echo json_encode(['status' => 'API da Clínica MF Diagnósticos está online.']);
        exit;
    }

    // Roteamento especial para endpoints complexos
    if ($resource === 'horarios-disponiveis') {
        $handler = new AgendaHandler($pdo);
        $result = $handler->getAvailableSlots($_GET);
        echo json_encode(['horarios' => $result]);
        exit;
    }

    // Roteamento genérico para CRUD
    $handler = new GenericHandler($pdo, $resource);
    $method = $_SERVER['REQUEST_METHOD'];
    $data = json_decode(file_get_contents('php://input'), true);

    switch ($method) {
        case 'GET':
            $result = $handler->handleGet($id);
            break;
        case 'POST':
            $result = $handler->handlePost($data);
            http_response_code(201); // Created
            break;
        case 'PUT':
            if (!$id) throw new Exception("ID é obrigatório para a operação PUT na URL (ex: /recurso/123).", 400);
            $result = $handler->handlePut($id, $data);
            break;
        case 'DELETE':
            if (!$id) throw new Exception("ID é obrigatório para a operação DELETE na URL (ex: /recurso/123).", 400);
            $result = $handler->handleDelete($id);
            break;
        default:
            throw new Exception("Método não suportado.", 405);
    }

    echo json_encode($result);

} catch (Exception $e) {
    // Tratamento de erro centralizado
    $code = is_int($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
