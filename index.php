<?php
/**
 * SISTEMA DE GESTÃO DE PELADA - VERSÃO PRODUÇÃO (HOSTINGER)
 * Funcionalidades: Login, Filtros, Busca, Histórico, Mobile-First
 */

header('Content-Type: text/html; charset=utf-8');
session_start();

// Segurança: Não exibir erros técnicos ao usuário final na Hostinger
ini_set('display_errors', 0);
error_reporting(E_ALL);

class Database {
    private $db;
    public function __construct() {
        try {
            $this->db = new PDO('sqlite:pelada.db');
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->exec("PRAGMA encoding = 'UTF-8'");
            $this->initDatabase();
        } catch(Exception $e) {
            die("Erro de ligação ao banco de dados.");
        }
    }
    private function initDatabase() {
        $this->db->exec("CREATE TABLE IF NOT EXISTS jogadores (id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT NOT NULL, ativo INTEGER DEFAULT 1)");
        $this->db->exec("CREATE TABLE IF NOT EXISTS pagamentos (id INTEGER PRIMARY KEY AUTOINCREMENT, jogador_id INTEGER, mes INTEGER, ano INTEGER, pago INTEGER DEFAULT 0, valor REAL DEFAULT 0, FOREIGN KEY (jogador_id) REFERENCES jogadores(id))");
        $this->db->exec("CREATE TABLE IF NOT EXISTS transacoes (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT, descricao TEXT, valor REAL, mes INTEGER, ano INTEGER, data_registro DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $this->db->exec("CREATE TABLE IF NOT EXISTS config (chave TEXT PRIMARY KEY, valor TEXT)");
        $this->db->exec("CREATE TABLE IF NOT EXISTS usuarios (id INTEGER PRIMARY KEY AUTOINCREMENT, usuario TEXT UNIQUE, senha TEXT)");
        
        $stmt = $this->db->query("SELECT * FROM config WHERE chave = 'saldo_atual'");
        if (!$stmt->fetch()) $this->db->exec("INSERT INTO config (chave, valor) VALUES ('saldo_atual', '0')");
        
        $userCheck = $this->db->query("SELECT * FROM usuarios WHERE usuario = 'admin'");
        if (!$userCheck->fetch()) {
            // Senha padrão: Bau2026@DioU@
            $hashPadrao = '$2y$10$9GvG7lC1Q.K5XUeS1XfXduIu0H0xV8v2GqFzXkR0f6S8fD/Y8hC2y';
            $this->db->prepare("INSERT INTO usuarios (usuario, senha) VALUES ('admin', ?)")->execute([$hashPadrao]);
        }
    }
    public function getConnection() { return $this->db; }
}

$database = new Database();
$db = $database->getConnection();

// --- LÓGICA DE LOGIN ---
if (isset($_POST['acao']) && $_POST['acao'] === 'login') {
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE usuario = ?");
    $stmt->execute([$_POST['usuario']]);
    $usuario = $stmt->fetch();

    if ($usuario && password_verify($_POST['senha'], $usuario['senha'])) {
        session_regenerate_id();
        $_SESSION['logado'] = true;
    } else {
        $erro_login = "Utilizador ou senha incorretos.";
    }
}

if (isset($_GET['logout'])) { session_destroy(); header("Location: index.php"); exit; }

if (!isset($_SESSION['logado'])):
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Login - Pelada Manager</title>
</head>
<body class="bg-slate-900 flex items-center justify-center h-screen px-4">
    <div class="bg-white p-8 rounded-2xl shadow-2xl w-full max-w-sm border-t-4 border-green-500">
        <div class="text-center mb-8">
            <span class="text-5xl">⚽</span>
            <h2 class="text-2xl font-black mt-4 text-gray-800 tracking-tight">Pelada Manager</h2>
            <p class="text-gray-400 text-xs uppercase font-bold tracking-widest mt-1">Acesso Restrito</p>
        </div>
        <?php if(isset($erro_login)): ?>
            <p class="bg-red-50 text-red-500 p-3 rounded-lg text-xs mb-4 text-center font-bold border border-red-100"><?= $erro_login ?></p>
        <?php endif; ?>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="acao" value="login">
            <input type="text" name="usuario" placeholder="Utilizador" required class="w-full p-4 bg-gray-50 border rounded-xl outline-none focus:ring-2 focus:ring-green-500 transition">
            <input type="password" name="senha" placeholder="Senha" required class="w-full p-4 bg-gray-50 border rounded-xl outline-none focus:ring-2 focus:ring-green-500 transition">
            <button class="w-full bg-green-600 text-white py-4 rounded-xl font-black hover:bg-green-700 shadow-lg shadow-green-200 transition uppercase tracking-wider">Entrar</button>
        </form>
    </div>
</body>
</html>
<?php exit; endif; ?>

<?php
// --- CONFIGURAÇÕES DE EXIBIÇÃO ---
$ano_selecionado = filter_input(INPUT_GET, 'ano', FILTER_SANITIZE_NUMBER_INT) ?: date('Y');
$busca = filter_input(INPUT_GET, 'busca', FILTER_SANITIZE_SPECIAL_CHARS) ?: '';
$ordem = $_GET['ordem'] ?? 'nome';
$mes_atual = date('n');

// --- PROCESSAMENTO SEGURO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'adicionar_jogador') {
        $nome = trim($_POST['nome']);
        if ($nome) {
            $stmt = $db->prepare("INSERT INTO jogadores (nome) VALUES (?)");
            $stmt->execute([$nome]);
            $id_novo = $db->lastInsertId();
            for ($m = 1; $m <= 12; $m++) {
                $db->prepare("INSERT INTO pagamentos (jogador_id, mes, ano) VALUES (?, ?, ?)")->execute([$id_novo, $m, $ano_selecionado]);
            }
        }
    }

    if ($acao === 'remover_jogador') {
        $db->prepare("UPDATE jogadores SET ativo = 0 WHERE id = ?")->execute([$_POST['id']]);
    }

    if ($acao === 'toggle_pagamento') {
        $id = intval($_POST['id']); $valor = floatval($_POST['valor']);
        $stmt = $db->prepare("SELECT * FROM pagamentos WHERE id = ?"); $stmt->execute([$id]);
        $pag = $stmt->fetch();
        $novo_estado = $pag['pago'] ? 0 : 1;
        $db->prepare("UPDATE pagamentos SET pago = ?, valor = ? WHERE id = ?")->execute([$novo_estado, $valor, $id]);
        
        $saldo = floatval($db->query("SELECT valor FROM config WHERE chave = 'saldo_atual'")->fetch()['valor']);
        $novo_saldo = $novo_estado ? $saldo + $valor : $saldo - $pag['valor'];
        $db->prepare("UPDATE config SET valor = ? WHERE chave = 'saldo_atual'")->execute([$novo_saldo]);
    }

    if ($acao === 'adicionar_transacao') {
        $db->prepare("INSERT INTO transacoes (tipo, descricao, valor, mes, ano) VALUES (?,?,?,?,?)")
           ->execute([$_POST['tipo'], trim($_POST['descricao']), floatval($_POST['valor']), $_POST['mes'], $_POST['ano']]);
        $saldo = floatval($db->query("SELECT valor FROM config WHERE chave = 'saldo_atual'")->fetch()['valor']);
        $n_saldo = ($_POST['tipo'] === 'receita') ? $saldo + $_POST['valor'] : $saldo - $_POST['valor'];
        $db->prepare("UPDATE config SET valor = ? WHERE chave = 'saldo_atual'")->execute([$n_saldo]);
    }

    if ($acao === 'remover_transacao') {
        $stmt = $db->prepare("SELECT * FROM transacoes WHERE id = ?"); $stmt->execute([$_POST['id']]);
        $t = $stmt->fetch();
        if ($t) {
            $saldo = floatval($db->query("SELECT valor FROM config WHERE chave = 'saldo_atual'")->fetch()['valor']);
            $n_saldo = ($t['tipo'] === 'receita') ? $saldo - $t['valor'] : $saldo + $t['valor'];
            $db->prepare("UPDATE config SET valor = ? WHERE chave = 'saldo_atual'")->execute([$n_saldo]);
            $db->prepare("DELETE FROM transacoes WHERE id = ?")->execute([$_POST['id']]);
        }
    }

    header("Location: index.php?ano=$ano_selecionado&busca=$busca&ordem=$ordem"); exit;
}

// --- CONSULTA SEGURA DOS JOGADORES ---
$params = [$ano_selecionado];
$sql = "SELECT j.*, (SELECT SUM(valor) FROM pagamentos WHERE jogador_id = j.id AND ano = ? AND pago = 1) as total_pago 
        FROM jogadores j WHERE ativo = 1";
if ($busca) { $sql .= " AND nome LIKE ?"; $params[] = "%$busca%"; }
$sql .= ($ordem === 'financeiro') ? " ORDER BY total_pago DESC" : " ORDER BY nome ASC";

$stmt = $db->prepare($sql); $stmt->execute($params);
$jogadores = $stmt->fetchAll(PDO::FETCH_ASSOC);

$meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
$saldo = $db->query("SELECT valor FROM config WHERE chave = 'saldo_atual'")->fetch()['valor'];
$stmt_t = $db->prepare("SELECT * FROM transacoes WHERE ano = ? ORDER BY mes DESC, data_registro DESC");
$stmt_t->execute([$ano_selecionado]);
$transacoes = $stmt_t->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Painel Financeiro - Pelada</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .sticky-col { position: sticky; left: 0; background: white; z-index: 10; border-right: 2px solid #f1f5f9; }
        .pay-btn { width: 34px; height: 34px; font-size: 10px; }
        @media (min-width: 768px) { .pay-btn { width: 44px; height: 44px; font-size: 12px; } }
        body { -webkit-tap-highlight-color: transparent; }
    </style>
</head>
<body class="bg-gray-50 pb-12">
    <div class="container mx-auto px-2 md:px-6 py-8">
        
        <header class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-2xl font-black text-slate-800 tracking-tighter">⚽ PELADA MANAGER</h1>
                <div class="flex gap-3 mt-1 font-bold text-[10px] text-slate-400 uppercase tracking-widest">
                    <span>Temporada <?= $ano_selecionado ?></span>
                    <a href="?logout=1" class="text-red-400 hover:underline">Sair do Sistema</a>
                </div>
            </div>
            <div class="text-right">
                <span class="text-[10px] font-black text-slate-300 uppercase tracking-widest block">Saldo em Caixa</span>
                <span class="text-2xl md:text-3xl font-black text-green-600">R$ <?= number_format($saldo, 2, ',', '.') ?></span>
            </div>
        </header>

        <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-100 mb-6 flex flex-wrap gap-3 items-center">
            <form method="GET" class="flex flex-wrap gap-2 w-full">
                <select name="ano" onchange="this.form.submit()" class="bg-slate-100 px-4 py-2 rounded-xl font-bold text-sm outline-none border-none">
                    <?php for($i=2024; $i<=2026; $i++): ?>
                        <option value="<?= $i ?>" <?= $i == $ano_selecionado ? 'selected' : '' ?>><?= $i ?></option>
                    <?php endfor; ?>
                </select>
                <input type="text" name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="Procurar jogador..." class="bg-slate-100 px-4 py-2 rounded-xl text-sm flex-1 outline-none border-none">
                <select name="ordem" onchange="this.form.submit()" class="bg-slate-100 px-4 py-2 rounded-xl text-xs font-black outline-none border-none">
                    <option value="nome" <?= $ordem == 'nome' ? 'selected' : '' ?>>A-Z</option>
                    <option value="financeiro" <?= $ordem == 'financeiro' ? 'selected' : '' ?>>TOP PAGADORES</option>
                </select>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden mb-8">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-slate-50 border-b border-slate-100">
                        <tr class="text-[10px] uppercase text-slate-400 font-black">
                            <th class="px-4 py-5 sticky-col">Jogador</th>
                            <?php foreach($meses as $idx => $m): ?>
                                <th class="px-1 text-center <?= ($idx+1) == $mes_atual ? 'text-blue-500 bg-blue-50/50' : '' ?>"><?= $m ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach($jogadores as $j): 
                            $stmt_p = $db->prepare("SELECT * FROM pagamentos WHERE jogador_id = ? AND ano = ? ORDER BY mes");
                            $stmt_p->execute([$j['id'], $ano_selecionado]);
                            $pags = $stmt_p->fetchAll();
                        ?>
                        <tr class="hover:bg-slate-50/80 transition">
                            <td class="px-4 py-4 sticky-col">
                                <span class="font-bold text-slate-700 block truncate w-32 md:w-56"><?= htmlspecialchars($j['nome']) ?></span>
                                <div class="flex gap-2 mt-1 items-center">
                                    <span class="text-[9px] font-black text-green-500 uppercase">R$<?= number_format($j['total_pago'], 0) ?></span>
                                    <button onclick="removerJogador(<?= $j['id'] ?>, '<?= $j['nome'] ?>')" class="text-[9px] text-slate-300 hover:text-red-400 font-bold uppercase">Remover</button>
                                </div>
                            </td>
                            <?php foreach($pags as $p): ?>
                            <td class="px-1 py-3 text-center">
                                <button onclick="togglePagamento(<?= $p['id'] ?>, <?= $p['pago'] ?>)" 
                                    class="pay-btn rounded-xl font-black transition-all inline-flex items-center justify-center shadow-sm
                                    <?= $p['pago'] ? 'bg-green-500 text-white shadow-green-100' : 'bg-slate-100 text-slate-300 border border-slate-200' ?>">
                                    <?= $p['pago'] ? 'OK' : '—' ?>
                                </button>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm">
                <h3 class="text-xs font-black text-slate-400 uppercase mb-4 tracking-widest">👤 Adicionar Novo Atleta</h3>
                <form method="POST" class="flex gap-2">
                    <input type="hidden" name="acao" value="adicionar_jogador">
                    <input type="text" name="nome" placeholder="Nome completo" required class="flex-1 px-4 py-3 bg-slate-50 border-none rounded-xl text-sm focus:ring-2 focus:ring-green-500 outline-none transition">
                    <button type="submit" class="bg-slate-800 text-white px-6 py-3 rounded-xl font-bold hover:bg-black transition shadow-lg shadow-slate-200">ADD</button>
                </form>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm">
                <h3 class="text-xs font-black text-slate-400 uppercase mb-4 tracking-widest">💸 Movimentação de Caixa</h3>
                <form method="POST" class="grid grid-cols-2 gap-2">
                    <input type="hidden" name="acao" value="adicionar_transacao">
                    <select name="tipo" class="px-3 py-3 bg-slate-50 border-none rounded-xl text-sm outline-none">
                        <option value="despesa">🔴 Despesa</option>
                        <option value="receita">🟢 Receita</option>
                    </select>
                    <input type="number" name="valor" placeholder="R$" step="0.01" required class="px-3 py-3 bg-slate-50 border-none rounded-xl text-sm outline-none">
                    <input type="text" name="descricao" placeholder="Ex: Aluguer Campo" required class="col-span-2 px-3 py-3 bg-slate-50 border-none rounded-xl text-sm outline-none">
                    <input type="hidden" name="mes" value="<?= $mes_atual ?>">
                    <input type="hidden" name="ano" value="<?= $ano_selecionado ?>">
                    <button type="submit" class="col-span-2 bg-blue-600 text-white py-3 rounded-xl font-black uppercase text-[10px] hover:bg-blue-700 transition">Confirmar Lançamento</button>
                </form>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
            <div class="p-5 border-b border-slate-50 bg-slate-50/50">
                <h2 class="text-xs font-black text-slate-400 uppercase tracking-widest">📜 Extrato da Temporada</h2>
            </div>
            <div class="divide-y divide-slate-50">
                <?php foreach($transacoes as $t): ?>
                <div class="p-4 flex justify-between items-center hover:bg-slate-50/50 transition">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center text-[10px] font-black <?= $t['tipo'] === 'receita' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600' ?>">
                            <?= $t['tipo'] === 'receita' ? 'IN' : 'OUT' ?>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-700"><?= htmlspecialchars($t['descricao']) ?></p>
                            <p class="text-[9px] font-bold text-slate-300 uppercase"><?= $meses[$t['mes']-1] ?> • <?= date('d/m/Y', strtotime($t['data_registro'])) ?></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-5">
                        <span class="text-sm font-black <?= $t['tipo'] === 'receita' ? 'text-green-600' : 'text-red-500' ?>">
                            <?= $t['tipo'] === 'receita' ? '+' : '-' ?> R$<?= number_format($t['valor'], 2, ',', '.') ?>
                        </span>
                        <form method="POST" onsubmit="return confirm('Apagar registo?')">
                            <input type="hidden" name="acao" value="remover_transacao">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button type="submit" class="text-slate-200 hover:text-red-400 transition text-lg">✕</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; if(empty($transacoes)): ?>
                    <p class="p-12 text-center text-[10px] text-slate-300 font-bold uppercase tracking-widest">Sem movimentos registados</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    async function togglePagamento(id, pago) {
        if (pago) {
            const r = await Swal.fire({ title: 'Estornar?', text: 'Deseja remover este pagamento do caixa?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444' });
            if (r.isConfirmed) post({ acao: 'toggle_pagamento', id, valor: 0 });
        } else {
            const { value: v } = await Swal.fire({ title: 'Registar Pagamento', input: 'number', inputValue: 50, showCancelButton: true });
            if (v) post({ acao: 'toggle_pagamento', id, valor: v });
        }
    }
    async function removerJogador(id, nome) {
        const r = await Swal.fire({ title: 'Remover '+nome+'?', text: 'Ele deixará de aparecer na lista ativa.', icon: 'question', showCancelButton: true });
        if (r.isConfirmed) post({ acao: 'remover_jogador', id });
    }
    function post(params) {
        const f = document.createElement('form'); f.method = 'POST';
        for (let k in params) { let i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = params[k]; f.appendChild(i); }
        document.body.appendChild(f); f.submit();
    }
    </script>
</body>
</html>