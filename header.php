<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cursos Online</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --primary-light: #eef2ff;
            --slate-900: #0f172a;
            --slate-800: #1e293b;
            --slate-600: #475569;
            --slate-500: #64748b;
            --slate-200: #e2e8f0;
            --slate-100: #f1f5f9;
            --slate-50: #f8fafc;
            --danger: #ef4444;
            --danger-bg: #fef2f2;
            --radius-lg: 16px;
            --radius-md: 10px;
            --shadow-card: 0 10px 25px -5px rgba(15, 23, 42, 0.08), 0 8px 10px -6px rgba(15, 23, 42, 0.04);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        body {
            background: linear-gradient(180deg, #f8fafc 0%, #eef2f6 100%);
            color: var(--slate-800);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .top-navbar {
            width: 100%;
            background: #ffffff;
            border-bottom: 1px solid var(--slate-200);
            padding: 0.85rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
            margin-bottom: 2rem;
        }

        .nav-brand {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--slate-900);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav-brand span {
            color: var(--primary);
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .nav-badge {
            background-color: var(--primary-light);
            color: var(--primary);
            font-size: 0.75rem;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .btn-nav-logout {
            padding: 6px 14px;
            background-color: var(--danger-bg);
            color: var(--danger);
            border: 1px solid #fee2e2;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn-nav-logout:hover {
            background-color: var(--danger);
            color: #ffffff;
        }

        .container {
            background: #ffffff;
            width: 100%;
            max-width: 480px;
            padding: 40px 36px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            border: 1px solid rgba(226, 232, 240, 0.9);
            margin-bottom: 40px;
        }

        h1 {
            color: var(--slate-900);
            font-size: 1.7rem;
            font-weight: 800;
            letter-spacing: -0.025em;
            margin-bottom: 1.5rem;
            text-align: center;
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        label {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--slate-600);
            margin-bottom: -6px;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid var(--slate-200);
            border-radius: var(--radius-md);
            font-size: 0.95rem;
            outline: none;
            transition: all 0.2s;
        }

        input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--primary-light);
        }

        button {
            background-color: var(--primary);
            color: #ffffff;
            border: none;
            padding: 13px 20px;
            font-size: 0.95rem;
            font-weight: 600;
            border-radius: var(--radius-md);
            cursor: pointer;
            margin-top: 10px;
            transition: background-color 0.2s;
        }

        button:hover {
            background-color: var(--primary-hover);
        }

        .error-box {
            background-color: var(--danger-bg);
            color: var(--danger);
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 12px;
            text-align: center;
        }
    </style>
</head>
<body>
    <header class="top-navbar">
        <a href="login.php" class="nav-brand">
            Cursos<span>Online</span>
        </a>
        <div class="nav-actions">
            <?php if (isset($_SESSION["usuario_id"])): ?>
                <span class="nav-badge"><?php echo htmlspecialchars($_SESSION["rol"]); ?></span>
                <a href="logout.php" class="btn-nav-logout">Cerrar sesión</a>
            <?php else: ?>
                <span class="nav-badge">Acceso</span>
            <?php endif; ?>
        </div>
    </header>

    <div class="container">