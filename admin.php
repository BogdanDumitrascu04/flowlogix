<?php
session_start();
$currentPage = 'admin';
require_once 'config.php';
require_once 'csrf.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
if ($_SESSION['role'] !== 'admin') {
    require __DIR__ . '/acces_interzis.php';
    exit;
}

$action = $_REQUEST['action'] ?? '';

// The public demo shows the users but never changes them.
if (!empty($demoMode) && in_array($action, ['add', 'edit', 'delete'], true)) {
    header("Location: admin.php?demo=readonly");
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !csrf_valid()) {
    http_response_code(403);
    exit('Cererea a fost respinsă: token de securitate invalid. Reîncarcă pagina și încearcă din nou.');
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? '');

    if ($name !== '' && $password !== '' && $role !== '') {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $sql = "INSERT INTO users (name, password, role) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $name, $passwordHash, $role);
        if ($stmt->execute()) {
            header("Location: admin.php?success=add");
            exit;
        }
    }
}

if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($id > 0 && $name !== '') {
        if ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $sql = "UPDATE users SET name = ?, password = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssi", $name, $passwordHash, $id);
        } else {
            // An empty password field keeps the current password.
            $sql = "UPDATE users SET name = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $name, $id);
        }
        if ($stmt->execute()) {
            header("Location: admin.php?success=edit");
            exit;
        }
    }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        $sql = "DELETE FROM users WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            header("Location: admin.php?success=delete");
            exit;
        }
    }
}

$users = [];
$sql = "SELECT id, name, role FROM users ORDER BY role, name";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $role = $row['role'];
        if (!isset($users[$role])) {
            $users[$role] = [];
        }
        $users[$role][] = $row;
    }
}

$roleLabels = [
    'tura' => 'Șef de tură',
    'depozit' => 'Șef de depozit',
    'manager' => 'Manager logistic',
    'admin' => 'Administrator sistem'
];

$roleColors = [
    'tura' => '#f59e0b',
    'depozit' => '#3b82f6',
    'manager' => '#8b5cf6',
    'admin' => '#ef4444'
];
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>FlowLogix | Administrare utilizatori</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="css/sidebar.css?v=14">
    <link rel="stylesheet" href="css/styles.css?v=14">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
    <style>
        .admin-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 32px;
            margin-top: 20px;
        }

        .admin-column {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .admin-column-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text);
            padding: 0 8px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .admin-column-badge {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .user-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: all 0.2s ease;
        }

        .user-card:hover {
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.08);
            border-color: var(--primary);
        }

        .user-name {
            font-weight: 600;
            color: var(--text);
            font-size: 1rem;
            margin: 0;
        }

        .user-actions {
            display: flex;
            gap: 8px;
        }

        .user-btn {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid var(--border);
            background: var(--surface-2);
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text);
            transition: 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .user-btn:hover {
            background: var(--primary-soft);
            border-color: var(--primary);
            color: var(--primary);
        }

        .user-btn.delete {
            border-color: var(--danger);
            color: var(--danger);
        }

        .user-btn.delete:hover {
            background: var(--danger-soft);
        }

        .add-user-card {
            background: var(--surface-2);
            border: 2px dashed var(--border);
            border-radius: 14px;
            padding: 32px 18px;
            text-align: center;
            cursor: pointer;
            transition: 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 120px;
            flex-direction: column;
            gap: 8px;
        }

        .add-user-card:hover {
            border-color: var(--primary);
            background: var(--primary-soft);
        }

        .add-user-icon {
            font-size: 2rem;
            color: var(--primary);
        }

        .add-user-text {
            font-weight: 600;
            color: var(--text);
            font-size: 0.95rem;
        }

        /* Modals */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.open {
            display: flex;
        }

        .modal-content {
            background: var(--surface);
            border-radius: 18px;
            padding: 32px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 20px 48px rgba(0, 0, 0, 0.15);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            margin-bottom: 24px;
        }

        .modal-header h2 {
            margin: 0 0 8px;
            font-size: 1.3rem;
            color: var(--text);
        }

        .modal-header p {
            margin: 0;
            color: var(--muted);
            font-size: 0.9rem;
        }

        .modal-form-group {
            margin-bottom: 16px;
        }

        .modal-form-group label {
            display: block;
            font-weight: 500;
            color: var(--text);
            margin-bottom: 6px;
            font-size: 0.9rem;
        }

        .modal-form-group input,
        .modal-form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface);
            color: var(--text);
            font-size: 0.95rem;
            font-family: inherit;
        }

        .modal-form-group input:focus,
        .modal-form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft);
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 28px;
        }

        .modal-btn {
            flex: 1;
            padding: 10px 16px;
            border: 1px solid var(--border);
            background: var(--surface-2);
            color: var(--text);
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s ease;
            font-size: 0.95rem;
        }

        .modal-btn:hover {
            background: var(--surface-3);
        }

        .modal-btn.primary {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .modal-btn.primary:hover {
            filter: brightness(0.95);
        }

        .demo-readonly-note {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--warning-soft, #fff7ed);
            border: 1px solid var(--warning, #f59e0b);
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 20px;
        }

        .success-message {
            background: var(--success-soft);
            border: 1px solid var(--success);
            color: var(--text);
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: none;
        }

        .success-message.show {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        @media (max-width: 1200px) {
            .admin-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 24px;
            }
        }

        @media (max-width: 768px) {
            .admin-grid {
                grid-template-columns: minmax(0, 1fr);
                gap: 20px;
                margin-top: 16px;
            }
        }

        .demo-section {
            margin-bottom: 24px;
        }

        .demo-section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text);
            padding: 0 8px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .demo-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            max-width: 680px;
        }

        .demo-card-body {
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }

        .demo-card-icon {
            font-size: 2rem;
            color: var(--primary);
            flex-shrink: 0;
            line-height: 1;
        }

        .demo-card-text h3 {
            margin: 0 0 6px;
            font-size: 1rem;
            font-weight: 600;
            color: var(--text);
        }

        .demo-card-text p {
            margin: 0;
            font-size: 0.875rem;
            color: var(--muted);
            line-height: 1.5;
        }

        .demo-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            background: var(--primary);
            color: #fff;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            white-space: nowrap;
            transition: filter 0.2s ease;
            flex-shrink: 0;
        }

        .demo-btn:hover {
            filter: brightness(0.92);
        }

        /* Phones: the button goes below the text */
        @media (max-width: 768px) {
            .demo-card {
                flex-direction: column;
                align-items: stretch;
                gap: 16px;
                padding: 18px;
            }

            .demo-btn {
                justify-content: center;
            }
        }

        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            padding-right: 40px;
            box-sizing: border-box;
        }

        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--muted);
            font-size: 1.1rem;
            padding: 0;
            line-height: 1;
            display: flex;
            align-items: center;
        }

        .password-toggle:hover {
            color: var(--text);
        }
    </style>
</head>
<body data-page="admin">
    <?php include 'sidebar.php'; ?>

    <main class="main">
        <div class="page-shell">
            <div class="breadcrumb">
                <span>FlowLogix</span>
                <i class='bx bx-chevron-right'></i>
                <strong>Admin</strong>
            </div>

            <div class="topbar">
                <div class="topbar-left">
                    <h1>Administrare utilizatori</h1>
                    <p>Gestionează utilizatorii sistemului pe baza rolurilor acestora.</p>
                </div>
            </div>

            <div class="success-message" id="successMessage">
                <i class='bx bx-check-circle'></i>
                <span id="successText">Operație realizată cu succes.</span>
            </div>

            <?php if (!empty($demoMode)): ?>
            <div class="demo-readonly-note">
                <i class='bx bx-lock-alt'></i>
                <span>
                    <?php echo isset($_GET['demo']) ? 'Modificarea nu a fost salvată. ' : ''; ?>
                    Demo public: utilizatorii pot fi văzuți, dar adăugarea, editarea și ștergerea sunt dezactivate.
                    <em>(Public demo: users are read-only.)</em>
                </span>
            </div>
            <?php endif; ?>

            <?php if ($_SESSION['role'] === 'admin'): ?>
            <div class="demo-section">
                <div class="demo-section-title">
                    <i class='bx bx-data'></i>
                    Administrare date demo
                </div>
                <div class="demo-card">
                    <div class="demo-card-body">
                        <div class="demo-card-icon">
                            <i class='bx bx-calendar-plus'></i>
                        </div>
                        <div class="demo-card-text">
                            <h3>Date demo pentru ziua curentă</h3>
                            <p>Adaugă înregistrări operaționale pentru ziua curentă, utile pentru testarea dashboard-urilor.</p>
                        </div>
                    </div>
                    <a href="add_today_demo.php" class="demo-btn">
                        <i class='bx bx-plus-circle'></i>
                        Adaugă date pentru azi
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <div class="admin-grid" id="adminGrid">
                <?php foreach (['tura', 'depozit', 'manager', 'admin'] as $role): ?>
                    <div class="admin-column">
                        <div class="admin-column-title">
                            <span class="admin-column-badge" style="background-color: <?php echo $roleColors[$role]; ?>"></span>
                            <?php echo $roleLabels[$role]; ?>
                        </div>

                        <div class="admin-column-users">
                            <?php
                            if (isset($users[$role])) {
                                foreach ($users[$role] as $user) {
                                    ?>
                                    <div class="user-card">
                                        <p class="user-name"><?php echo htmlspecialchars($user['name']); ?></p>
                                        <div class="user-actions">
                                            <button class="user-btn" onclick="openEditModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>')">
                                                <i class='bx bx-edit-alt'></i>
                                                Editare
                                            </button>
                                            <button class="user-btn delete" onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>')">
                                                <i class='bx bx-trash'></i>
                                                Ștergere
                                            </button>
                                        </div>
                                    </div>
                                    <?php
                                }
                            }
                            ?>

                            <div class="add-user-card" onclick="openAddModal('<?php echo $role; ?>')">
                                <div class="add-user-icon">+</div>
                                <div class="add-user-text">Adaugă utilizator</div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    </main>

    <!-- Add user -->
    <div class="modal" id="addModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Nou utilizator</h2>
                <p>Adaugă un utilizator nou în sistem.</p>
            </div>
            <form id="addForm" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="role" id="addRole" value="">

                <div class="modal-form-group">
                    <label for="addName">Nume utilizator</label>
                    <input type="text" id="addName" name="name" placeholder="Ex: Andrei" required>
                </div>

                <div class="modal-form-group">
                    <label for="addPassword">Parolă</label>
                    <input type="password" id="addPassword" name="password" placeholder="Introdu parola" required>
                </div>

                <div class="modal-actions">
                    <button type="button" class="modal-btn" onclick="closeModal('addModal')">Anulare</button>
                    <button type="submit" class="modal-btn primary">Adaugă</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit user -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Editare utilizator</h2>
                <p>Modifică datele utilizatorului.</p>
            </div>
            <form id="editForm" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editId" value="">

                <div class="modal-form-group">
                    <label for="editName">Nume utilizator</label>
                    <input type="text" id="editName" name="name" placeholder="Ex: Andrei" required>
                </div>

                <div class="modal-form-group">
                    <label for="editPassword">Parolă nouă</label>
                    <div class="password-wrapper">
                        <input type="password" id="editPassword" name="password" placeholder="Lasă gol pentru a păstra parola actuală">
                        <button type="button" class="password-toggle" onclick="toggleEditPassword()" tabindex="-1" aria-label="Arată/ascunde parola">
                            <i class="bx bx-show" id="editPasswordIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="modal-btn" onclick="closeModal('editModal')">Anulare</button>
                    <button type="submit" class="modal-btn primary">Salvează</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddModal(role) {
            document.getElementById('addRole').value = role;
            document.getElementById('addName').value = '';
            document.getElementById('addPassword').value = '';
            document.getElementById('addModal').classList.add('open');
        }

        function openEditModal(id, name) {
            document.getElementById('editId').value = id;
            document.getElementById('editName').value = name;
            document.getElementById('editPassword').value = '';
            document.getElementById('editPassword').type = 'password';
            document.getElementById('editPasswordIcon').className = 'bx bx-show';
            document.getElementById('editModal').classList.add('open');
        }

        function toggleEditPassword() {
            const input = document.getElementById('editPassword');
            const icon  = document.getElementById('editPasswordIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'bx bx-hide';
            } else {
                input.type = 'password';
                icon.className = 'bx bx-show';
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('open');
        }

        function deleteUser(id, name) {
            if (confirm(`Ești sigur că vrei să ștergi utilizatorul "${name}"?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // A click on the dimmed background closes the modal.
        document.addEventListener('click', function(event) {
            const addModal = document.getElementById('addModal');
            const editModal = document.getElementById('editModal');
            if (event.target === addModal) addModal.classList.remove('open');
            if (event.target === editModal) editModal.classList.remove('open');
        });

        // ?success= is set by the redirect after add / edit / delete.
        document.addEventListener('DOMContentLoaded', function() {
            const params = new URLSearchParams(window.location.search);
            const success = params.get('success');
            if (success) {
                const messages = {
                    'add': 'Utilizator adăugat cu succes.',
                    'edit': 'Utilizator modificat cu succes.',
                    'delete': 'Utilizator șters cu succes.'
                };
                const successMsg = document.getElementById('successMessage');
                const successText = document.getElementById('successText');
                if (messages[success]) {
                    successText.textContent = messages[success];
                    successMsg.classList.add('show');
                    setTimeout(() => {
                        successMsg.classList.remove('show');
                    }, 4000);
                }
            }
        });
    </script>
</body>
</html>
