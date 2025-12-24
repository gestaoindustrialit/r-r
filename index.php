<?php
session_start();

// Ensure storage directory exists
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

// Database bootstrap
$dbPath = $dataDir . '/romantico.db';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Define route locations
$locations = [
    'Mosteiro de Santa Maria de Pombeiro',
    'Mosteiro do Salvador de Travanca',
    'Mosteiro de São Pedro de Ferreira',
    'Mosteiro de Paço de Sousa',
    'Mosteiro de Cête',
    'Mosteiro do Salvador de Mancelos',
    'Mosteiro de Vila Boa do Bispo',
    'Mosteiro de Santa Maria de Arouca',
    'Mosteiro de Santo André de Ancede',
    'Mosteiro de Santa Maria de Pombeiro',
    'Mosteiro do Salvador de Freixo de Baixo',
    'Mosteiro de Bustelo',
    'Igreja de São Pedro de Abragão',
    'Igreja de São Pedro de Aboim',
    'Igreja de São Vicente de Sousa',
    'Igreja de São Gens de Boelhe',
    'Igreja de Santa Maria de Airães',
    'Igreja de São Miguel de Entre-os-Rios',
    'Igreja de São Pedro de Rates',
    'Igreja de São Salvador de Aveleda',
    'Igreja de São Martinho de Soalhães',
    'Igreja de São Tiago de Valadares',
    'Igreja de São Mamede de Vila Verde',
    'Igreja de São Martinho de Foz do Sousa',
    'Igreja de Santa Maria de Lufrei',
    'Igreja de São Pedro de Balsemão',
    'Igreja de Santa Maria de Gondar',
    'Igreja de São João Baptista de Gatão',
    'Igreja de Santa Maria de Travanca',
    'Igreja de São Tiago de Antas',
    'Igreja de Santa Maria de Meinedo',
    'Igreja de São Tiago de Telões',
    'Igreja de Santa Maria de Gestaçô',
    'Igreja de Santa Marinha de Vila Marim',
    'Igreja de São Miguel de Bustelo',
    'Igreja de São Mamede de Vila Chã',
    'Igreja de São Clemente de Tarouquela',
    'Igreja de Santa Maria de Vila Boa de Quires',
    'Igreja de São Pedro de Tendais',
    'Igreja de São Salvador de Ribas',
    'Igreja de São Pedro de Castelões',
    'Igreja de Santa Maria de Lardosa',
    'Igreja de Santo André de Telões',
    'Torre de Vilar',
    'Torre de Alpendurada',
    'Torre de Penafiel',
    'Memorial da Ermida',
    'Memorial de Sobrado',
    'Memorial de Alpendurada',
    'Memorial de Lordelo',
    'Ponte de Espindo',
    'Ponte de Esposende',
    'Ponte do Arco de Sardoura',
    'Ponte de Ucanha',
    'Ponte de Soalhães',
    'Ponte de Canavezes',
    'Castelo de Arnoia',
    'Castelo de Monte Mozinho'
];

function ensureDatabase(PDO $db, array $locations): array
{
    $db->exec('CREATE TABLE IF NOT EXISTS locais (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL,
        imagem TEXT NOT NULL,
        link TEXT NOT NULL DEFAULT "",
        data_visita TEXT
    )');

    $columns = $db->query('PRAGMA table_info(locais)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('link', $columns, true)) {
        $db->exec('ALTER TABLE locais ADD COLUMN link TEXT NOT NULL DEFAULT ""');
    }

    $existing = $db->query('SELECT nome, COUNT(*) as total FROM locais GROUP BY nome')->fetchAll(PDO::FETCH_KEY_PAIR);
    $insert = $db->prepare('INSERT INTO locais (nome, imagem, link) VALUES (:nome, :imagem, :link)');
    $updateLink = $db->prepare('UPDATE locais SET link = :link WHERE id = :id');
    $desiredCounts = [];
    foreach ($locations as $name) {
        $desiredCounts[$name] = ($desiredCounts[$name] ?? 0) + 1;
        $currentCount = (int) ($existing[$name] ?? 0);
        if ($currentCount < $desiredCounts[$name]) {
            $insert->execute([
                ':nome' => $name,
                ':imagem' => buildPlaceholder($name),
                ':link' => buildMapLink($name),
            ]);
            $existing[$name] = $currentCount + 1;
        }
    }

    $rows = $db->query('SELECT id, nome, imagem, link, data_visita FROM locais ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        if (empty($row['link'])) {
            $updateLink->execute([
                ':link' => buildMapLink($row['nome']),
                ':id' => $row['id'],
            ]);
        }
    }

    $rows = $db->query('SELECT id, nome, imagem, link, data_visita FROM locais ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    return $rows ?: [];
}

// Utility to generate Google Maps link
function buildMapLink(string $name): string
{
    $query = urlencode($name . ' Rota do Romântico');
    return "https://www.google.com/maps/search/?api=1&query={$query}";
}

// Utility to generate placeholder image
function buildPlaceholder(string $name): string
{
    return 'https://via.placeholder.com/320x200?text=' . urlencode($name);
}

// Handle saving visits
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save') {
    header('Content-Type: application/json');
    if (empty($_SESSION['authenticated'])) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Não autenticado.']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Formato inválido.']);
        exit;
    }

    ensureDatabase($db, $locations);
    $db->beginTransaction();
    $stmt = $db->prepare('UPDATE locais SET data_visita = :data WHERE id = :id');
    foreach ($payload as $key => $date) {
        if (!preg_match('/^loc_(\d+)$/', $key, $matches)) {
            continue;
        }
        $id = (int) $matches[1];
        $stmt->execute([
            ':data' => $date ?: null,
            ':id' => $id,
        ]);
    }
    $db->commit();

    echo json_encode(['status' => 'ok']);
    exit;
}

// Load saved visits and locations
$locationsData = ensureDatabase($db, $locations);
$savedVisits = [];
foreach ($locationsData as $row) {
    $savedVisits['loc_' . $row['id']] = $row['data_visita'] ?? '';
}

// Handle login form
$loginError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $pin = $_POST['pin'] ?? '';
    if ($pin !== '2002') {
        $loginError = 'PIN incorreto. Use 2002.';
    } else {
        $_SESSION['authenticated'] = true;
    }
}

$isAuthenticated = !empty($_SESSION['authenticated']);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Rota do Romântico - Diário de Visitas</title>
    <style>
        :root {
            --primary: #6b3c8f;
            --secondary: #f3e9ff;
            --accent: #ed7f2f;
            --bg: #f7f7fb;
            --text: #1c1c28;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 0;
        }
        header {
            background: linear-gradient(135deg, var(--primary), #4c2c6b);
            color: white;
            padding: 24px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            position: relative;
            overflow: hidden;
        }
        header .hero {
            margin: 18px auto 0;
            max-width: 960px;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            border: 3px solid rgba(255,255,255,0.6);
        }
        header .hero img {
            width: 100%;
            display: block;
            height: 260px;
            object-fit: cover;
        }
        main { padding: 24px; max-width: 1200px; margin: auto; }
        .card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.08);
            margin-bottom: 24px;
        }
        .login-form {
            display: grid;
            gap: 12px;
            max-width: 420px;
            margin: 0 auto;
        }
        label { font-weight: 600; }
        input[type="file"], input[type="password"], input[type="text"], input[type="date"] {
            padding: 10px 12px;
            border: 1px solid #d7d7e0;
            border-radius: 8px;
            font-size: 14px;
            background: #fff;
        }
        button {
            padding: 12px 16px;
            border: none;
            border-radius: 10px;
            background: var(--primary);
            color: white;
            font-weight: 700;
            cursor: pointer;
            transition: transform .1s ease, box-shadow .1s ease;
        }
        button:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,0.12); }
        button:disabled { opacity: 0.6; cursor: not-allowed; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
        }
        .location-card {
            border: 1px solid #ececf2;
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
            display: flex;
            flex-direction: column;
        }
        .location-card img {
            width: 100%;
            height: 160px;
            object-fit: cover;
            background: #e8defa;
        }
        .location-card .content {
            padding: 12px 14px 16px;
            display: grid;
            gap: 8px;
        }
        .location-card h3 { margin: 0; font-size: 16px; }
        .map-link { color: var(--accent); font-weight: 600; text-decoration: none; }
        .map-link:hover { text-decoration: underline; }
        .status { padding: 10px 12px; border-radius: 8px; background: #fff4e5; border: 1px solid #f5d8b5; color: #9b5a00; margin-top: 12px; }
        .success { background: #e8f7ed; border-color: #b7e0c4; color: #1c7a3d; }
        .error { background: #fdecec; border-color: #f5c2c2; color: #a12b2b; }
        .entry-photo {
            max-width: 200px;
            border-radius: 12px;
            border: 3px solid rgba(255,255,255,0.7);
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        }
        .auth-summary {
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
        }
        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        @media print {
            body { background: #fff; }
            header, #saveStatus, #saveBtn, #printBtn, .auth-summary button { display: none; }
            main { padding: 0; }
            .card { box-shadow: none; border: none; }
            .location-card { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<header>
    <h1>Diário da Rota do Romântico</h1>
    <p>Veja a foto de abertura, introduza o PIN 2002 e registe cada visita.</p>
    <div class="hero">
        <img src="https://images.unsplash.com/photo-1512453979798-5ea266f8880c?auto=format&fit=crop&w=1600&q=80" alt="Rota do Romântico" />
    </div>
</header>
<main>
    <?php if (!$isAuthenticated): ?>
        <section class="card">
            <h2>Entrada</h2>
            <form class="login-form" method="POST">
                <label for="pin">PIN de acesso</label>
                <input id="pin" name="pin" type="password" inputmode="numeric" placeholder="2002" required />

                <?php if ($loginError): ?>
                    <div class="status error"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <button type="submit" name="login">Entrar</button>
            </form>
        </section>
    <?php else: ?>
        <section class="card">
            <div class="auth-summary">
                <div>
                    <h2>Bem-vindo(a)</h2>
                    <p>PIN correto. Preencha a data de visita de cada local, guarde e imprima o registo com uma foto de cada local.</p>
                    <div class="actions">
                        <button id="saveBtn">Guardar visitas</button>
                        <button id="printBtn" type="button">Imprimir fotos</button>
                        <a href="logout.php" class="map-link" style="align-self:center;">Terminar sessão</a>
                    </div>
                    <div id="saveStatus" class="status" style="display:none"></div>
                </div>
            </div>
        </section>

        <section class="card">
            <h2>Locais da rota (58)</h2>
            <div class="grid">
                <?php foreach ($locationsData as $row):
                    $id = 'loc_' . $row['id'];
                    $name = $row['nome'];
                    $map = $row['link'] ?: buildMapLink($name);
                    $img = $row['imagem'];
                    $saved = $savedVisits[$id] ?? '';
                    ?>
                    <article class="location-card" data-id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>">
                        <img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8'); ?>" alt="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" />
                        <div class="content">
                            <h3><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></h3>
                            <a class="map-link" href="<?= htmlspecialchars($map, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Ver no Google Maps</a>
                            <label for="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>">Data da visita</label>
                            <input type="date" id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>" value="<?= htmlspecialchars($saved, ENT_QUOTES, 'UTF-8'); ?>" />
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</main>
<script>
    const saveBtn = document.getElementById('saveBtn');
    const saveStatus = document.getElementById('saveStatus');
    const printBtn = document.getElementById('printBtn');

    function showStatus(message, type = '') {
        if (!saveStatus) return;
        saveStatus.textContent = message;
        saveStatus.className = 'status ' + type;
        saveStatus.style.display = 'block';
    }

    async function saveVisits() {
        if (!saveBtn) return;
        saveBtn.disabled = true;
        const inputs = document.querySelectorAll('.location-card input[type="date"]');
        const payload = {};
        inputs.forEach(input => {
            payload[input.id] = input.value;
        });

        try {
            const response = await fetch('?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            if (!response.ok) {
                throw new Error('Erro ao guardar (' + response.status + ').');
            }
            const result = await response.json();
            if (result.status === 'ok') {
                showStatus('Visitas guardadas com sucesso!', 'success');
            } else {
                throw new Error(result.message || 'Erro desconhecido.');
            }
        } catch (err) {
            showStatus(err.message, 'error');
        } finally {
            saveBtn.disabled = false;
        }
    }

    if (saveBtn) {
        saveBtn.addEventListener('click', saveVisits);
    }

    if (printBtn) {
        printBtn.addEventListener('click', () => window.print());
    }
</script>
</body>
</html>
