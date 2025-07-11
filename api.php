<?php
/*
 * =================================================================================================
 * API Completa em PHP para o Sistema de Gestão da Clínica (Versão Refatorada)
 * =================================================================================================
 *
 * Descrição:
 * Este arquivo único contém uma API PHP completa e refatorada. A nova estrutura utiliza
 * namespaces para melhor organização, um roteador centralizado e tratamento de erros aprimorado,
 * mantendo a simplicidade de um único arquivo para deployment.
 *
 * --- INSTRUÇÕES DE CONFIGURAÇÃO DO SERVIDOR (Easypanel / Nginx) ---
 *
 * O erro "404 Not Found" ocorre porque o Nginx precisa de uma regra para direcionar todas as
 * requisições para este arquivo `api.php`.
 *
 * 1. No seu painel do Easypanel, vá para o seu projeto.
 * 2. Acesse a aba "Settings" (Configurações).
 * 3. Encontre a seção "Nginx Configuration" (Configuração do Nginx).
 * 4. Cole o seguinte bloco de código e salve:
 *
 * location / {
 * try_files $uri $uri/ /api.php?$query_string;
 * }
 *
 * 5. O Easypanel aplicará a configuração e reiniciará o serviço. Isso resolverá o erro 404.
 *
 * =================================================================================================
 */


// =================================================================================================
// SEÇÃO 1: BOOTSTRAP E CONFIGURAÇÃO
// =================================================================================================

// Definição de constantes de configuração
define('DB_HOST', '69.62.93.202');
define('DB_NAME', 'doctorbot');
define('DB_USER', 'root');
define('DB_PASS', '9ac55e0e489bc5d43bbc');
define('DB_PORT', '3306');
define('DB_CHARSET', 'utf8mb4');

// Função de tratamento de erro centralizada
function handleError(Throwable $e) {
    $code = is_int($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode([
        'error' => [
            'message' => $e->getMessage(),
            'code' => $code,
        ]
    ]);
    exit;
}

set_exception_handler('handleError');

// Configuração dos headers HTTP
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");

// Tratamento para requisições OPTIONS (pre-flight) do CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // No Content
    exit();
}


// =================================================================================================
// SEÇÃO 2: DEFINIÇÃO DAS CLASSES (LÓGICA DA APLICAÇÃO)
// =================================================================================================

namespace Api\Database {
    use PDO;
    use PDOException;

    /**
     * Classe Database (Singleton)
     * Gerencia a conexão PDO com o banco de dados.
     */
    class Database {
        private static ?self $instance = null;
        public PDO $pdo;

        private function __construct() {
            $dsn = 'mysql:host=' . \DB_HOST . ';port=' . \DB_PORT . ';dbname=' . \DB_NAME . ';charset=' . \DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            try {
                $this->pdo = new PDO($dsn, \DB_USER, \DB_PASS, $options);
            } catch (PDOException $e) {
                throw new PDOException($e->getMessage(), (int)$e->getCode());
            }
        }

        public static function getInstance(): self {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }
    }
}

namespace Api\Handlers {
    use PDO;
    use Exception;
    use DateTime;
    use DateInterval;
    use DatePeriod;

    /**
     * Classe GenericHandler
     * Lida com as operações CRUD para qualquer tabela.
     */
    class GenericHandler {
        public function __construct(private PDO $pdo, private string $table) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $this->table)) {
                throw new Exception("Nome de tabela inválido.", 400);
            }
        }

        public function get($id = null) {
            if ($id) {
                $stmt = $this->pdo->prepare("SELECT * FROM `{$this->table}` WHERE id = ?");
                $stmt->execute([$id]);
                $result = $stmt->fetch();
                if (!$result) throw new Exception("Registro não encontrado.", 404);
                return $result;
            }
            $stmt = $this->pdo->query("SELECT * FROM `{$this->table}`");
            return $stmt->fetchAll();
        }

        public function create($data) {
            if (empty($data)) throw new Exception("O corpo da requisição não pode ser vazio.", 400);
            $columns = implode(', ', array_map(fn($col) => "`$col`", array_keys($data)));
            $placeholders = implode(', ', array_fill(0, count($data), '?'));
            $sql = "INSERT INTO `{$this->table}` ($columns) VALUES ($placeholders)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_values($data));
            return ['message' => 'Registro criado com sucesso.', 'id' => $this->pdo->lastInsertId()];
        }

        public function update($id, $data) {
            if (empty($data)) throw new Exception("O corpo da requisição não pode ser vazio.", 400);
            $fields = implode(', ', array_map(fn($col) => "`$col` = ?", array_keys($data)));
            $sql = "UPDATE `{$this->table}` SET $fields WHERE id = ?";
            $values = array_values($data);
            $values[] = $id;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($values);
            if ($stmt->rowCount() === 0) throw new Exception("Nenhum registro encontrado ou alterado.", 404);
            return ['message' => 'Registro atualizado com sucesso.'];
        }

        public function delete($id) {
            $sql = "DELETE FROM `{$this->table}` WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) throw new Exception("Registro não encontrado.", 404);
            return ['message' => 'Registro deletado com sucesso.'];
        }
    }

    /**
     * Classe AgendaHandler
     * Lida com a lógica de negócio complexa para encontrar horários disponíveis.
     */
    class AgendaHandler {
        public function __construct(private PDO $pdo) {}

        public function getAvailableSlots($params) {
            if (!isset($params['recurso_id']) || !isset($params['data'])) {
                throw new Exception("Parâmetros 'recurso_id' e 'data' são obrigatórios.", 400);
            }
            $recurso_id = filter_var($params['recurso_id'], FILTER_VALIDATE_INT);
            $data_str = $params['data'];

            $regras = $this->getAvailabilityRules($recurso_id, $data_str);
            if (empty($regras)) return [];

            $ocupados = $this->getOccupiedSlots($recurso_id, $data_str);
            $todos_slots = $this->generateAllPossibleSlots($regras, $data_str);
            
            $disponiveis = array_udiff($todos_slots, $ocupados, fn($a, $b) => $a <=> $b);

            return $this->applyBusinessRules($disponiveis, $recurso_id, $params);
        }

        private function getAvailabilityRules($recurso_id, $data_str) {
            $dia_semana = date('N', strtotime($data_str)) % 7 + 1;
            $stmt = $this->pdo->prepare("SELECT * FROM regras_disponibilidade WHERE recurso_id = ? AND dia_semana = ?");
            $stmt->execute([$recurso_id, $dia_semana]);
            return $stmt->fetchAll();
        }

        private function getOccupiedSlots($recurso_id, $data_str) {
            $stmt = $this->pdo->prepare("SELECT data_hora_inicio FROM agendamentos WHERE recurso_id = ? AND DATE(data_hora_inicio) = ? AND status_agendamento NOT IN ('CANCELADO_PACIENTE', 'CANCELADO_CLINICA')");
            $stmt->execute([$recurso_id, $data_str]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        }

        private function generateAllPossibleSlots($regras, $data_str) {
            $slots = [];
            foreach ($regras as $regra) {
                $inicio = new DateTime($data_str . ' ' . $regra['hora_inicio']);
                $fim = new DateTime($data_str . ' ' . $regra['hora_fim']);
                $intervalo = new DateInterval('PT' . $regra['duracao_slot_minutos'] . 'M');
                $periodo = new DatePeriod($inicio, $intervalo, $fim);
                foreach ($periodo as $slot) {
                    $slots[] = $slot->format('Y-m-d H:i:s');
                }
            }
            return $slots;
        }

        private function applyBusinessRules($slots, $recurso_id, $params) {
            $stmt = $this->pdo->prepare("SELECT * FROM regras_restricao_agenda WHERE recurso_id = ?");
            $stmt->execute([$recurso_id]);
            $restricoes = $stmt->fetchAll();
            if (empty($restricoes)) {
                return array_map(fn($dt) => date('H:i', strtotime($dt)), $slots);
            }

            $procedimento = isset($params['procedimento_id']) ? $this->getProcedure($params['procedimento_id']) : null;
            $convenio_id = $params['convenio_id'] ?? null;

            $slots_filtrados = array_filter($slots, function($slot_str) use ($restricoes, $convenio_id, $procedimento) {
                $slot_time = date('H:i:s', strtotime($slot_str));
                foreach ($restricoes as $r) {
                    if ($slot_time >= ($r['hora_inicio'] ?? '00:00:00') && $slot_time < ($r['hora_fim'] ?? '23:59:59')) {
                        if ($r['tipo_restricao'] === 'RESTRINGE_CONVENIO' && $convenio_id == $r['id_referencia']) return false;
                        if ($r['tipo_restricao'] === 'PERMITE_APENAS_CONVENIO' && $convenio_id != $r['id_referencia']) return false;
                        if ($r['tipo_restricao'] === 'SOMENTE_COM_CONTRASTE' && (!$procedimento || !$procedimento['requer_contraste'])) return false;
                    }
                }
                return true;
            });

            return array_map(fn($dt) => date('H:i', strtotime($dt)), $slots_filtrados);
        }

        private function getProcedure($id) {
            $stmt = $this->pdo->prepare("SELECT * FROM procedimentos WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch();
        }
    }
}


// =================================================================================================
// SEÇÃO 3: ROTEADOR E EXECUÇÃO
// =================================================================================================

namespace Api\Router {
    use Api\Database\Database;
    use Api\Handlers\{GenericHandler, AgendaHandler};
    use Exception;

    class Router {
        private \PDO $pdo;

        public function __construct() {
            $this->pdo = Database::getInstance()->pdo;
        }

        public function run() {
            $path = trim($_GET['path'] ?? '', '/');
            $parts = explode('/', $path);
            $resource = $parts[0] ?? null;
            $id = filter_var($parts[1] ?? null, FILTER_VALIDATE_INT);
            $method = $_SERVER['REQUEST_METHOD'];

            if (!$resource) {
                echo json_encode(['status' => 'API da Clínica MF Diagnósticos está online.']);
                return;
            }

            if ($resource === 'horarios-disponiveis') {
                $handler = new AgendaHandler($this->pdo);
                $result = $handler->getAvailableSlots($_GET);
                echo json_encode(['horarios' => $result]);
                return;
            }

            $handler = new GenericHandler($this->pdo, $resource);
            $data = json_decode(file_get_contents('php://input'), true);

            $result = match ($method) {
                'GET'    => $handler->get($id),
                'POST'   => $this->withStatus(201, fn() => $handler->create($data)),
                'PUT'    => $this->withStatus(200, fn() => $handler->update($this->ensureId($id), $data)),
                'DELETE' => $this->withStatus(200, fn() => $handler->delete($this->ensureId($id))),
                default  => throw new Exception("Método não suportado.", 405),
            };

            echo json_encode($result);
        }

        private function ensureId($id) {
            if (!$id) throw new Exception("Um ID numérico é obrigatório na URL.", 400);
            return $id;
        }

        private function withStatus(int $code, callable $callback) {
            http_response_code($code);
            return $callback();
        }
    }

    // Ponto de entrada da API
    $router = new Router();
    $router->run();
}
?>
