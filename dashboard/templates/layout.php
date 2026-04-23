<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $content */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #07111f;
            --bg-alt: #091a2f;
            --panel: rgba(11, 24, 44, 0.86);
            --panel-strong: rgba(16, 35, 62, 0.92);
            --text: #ecf4ff;
            --muted: #97abc6;
            --accent: #7dd3fc;
            --accent-strong: #38bdf8;
            --success: #8bd3a8;
            --warning: #f5c66b;
            --border: rgba(148, 163, 184, 0.16);
            --shadow: 0 22px 60px rgba(1, 6, 17, 0.34);
        }

        * { box-sizing: border-box; }
        html {
            scroll-behavior: smooth;
        }
        body {
            margin: 0;
            font-family: "Manrope", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at 15% 10%, rgba(56, 189, 248, 0.18), transparent 28%),
                radial-gradient(circle at 85% 0%, rgba(34, 197, 94, 0.08), transparent 22%),
                linear-gradient(180deg, var(--bg-alt), var(--bg));
            color: var(--text);
        }
        .shell {
            max-width: 1240px;
            margin: 0 auto;
            padding: 28px 20px 56px;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 26px;
            padding: 14px 18px;
            border: 1px solid var(--border);
            border-radius: 18px;
            background: rgba(6, 14, 27, 0.55);
            backdrop-filter: blur(10px);
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }
        .mark {
            width: 40px;
            height: 40px;
            display: grid;
            place-items: center;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(125, 211, 252, 0.22), rgba(56, 189, 248, 0.55));
            color: white;
            font-weight: 800;
            box-shadow: var(--shadow);
        }
        .brand strong {
            display: block;
            font-size: 0.98rem;
            letter-spacing: 0.01em;
        }
        .brand span {
            display: block;
            color: var(--muted);
            font-size: 0.82rem;
        }
        .nav {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 10px;
        }
        .nav a {
            color: var(--text);
            text-decoration: none;
            font-size: 0.9rem;
            padding: 8px 12px;
            border: 1px solid transparent;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.06);
        }
        .nav a:hover {
            border-color: var(--border);
            background: rgba(125, 211, 252, 0.12);
        }
        .hero {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 24px;
            margin-bottom: 28px;
            padding: 28px;
            border: 1px solid var(--border);
            border-radius: 28px;
            background: linear-gradient(180deg, rgba(17, 33, 58, 0.92), rgba(8, 18, 33, 0.88));
            box-shadow: var(--shadow);
        }
        .hero-copy {
            max-width: 720px;
        }
        h1 {
            margin: 0 0 8px;
            font-size: clamp(2rem, 4.8vw, 3.9rem);
            line-height: 0.95;
            letter-spacing: -0.05em;
        }
        p {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
        }
        .badge {
            border: 1px solid var(--border);
            background: rgba(11, 24, 44, 0.78);
            padding: 10px 14px;
            border-radius: 999px;
            color: var(--accent);
            white-space: nowrap;
        }
        .badge--subtle {
            color: var(--muted);
            background: rgba(148, 163, 184, 0.08);
        }
        .objective {
            background: linear-gradient(180deg, rgba(17, 33, 58, 0.92), rgba(8, 18, 33, 0.88));
        }
        .objective-layout {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 16px;
            align-items: stretch;
        }
        .objective-panel {
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 20px;
            background: rgba(6, 14, 27, 0.34);
        }
        .objective-kpis {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 16px;
        }
        .objective-kpi {
            padding: 14px;
            border-radius: 16px;
            background: rgba(148, 163, 184, 0.06);
            border: 1px solid rgba(148, 163, 184, 0.08);
        }
        .objective-kpi strong {
            display: block;
            margin-top: 5px;
            font-size: 1.2rem;
        }
        .progress {
            margin-top: 16px;
        }
        .progress-track {
            height: 12px;
            border-radius: 999px;
            overflow: hidden;
            background: rgba(148, 163, 184, 0.12);
            border: 1px solid rgba(148, 163, 184, 0.08);
        }
        .progress-fill {
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--accent-strong), var(--success));
        }
        .progress-meta {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-top: 8px;
            color: var(--muted);
            font-size: 0.9rem;
        }
        .section {
            margin-top: 26px;
        }
        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 16px;
            margin-bottom: 14px;
        }
        .section-title {
            margin: 0;
            font-size: 1.2rem;
            letter-spacing: -0.02em;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 16px;
        }
        .channel-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }
        .card {
            background: linear-gradient(180deg, var(--panel), rgba(7, 17, 31, 0.9));
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 18px;
            min-height: 132px;
            box-shadow: var(--shadow);
        }
        .label {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--muted);
            margin-bottom: 10px;
        }
        .value {
            font-size: 2.05rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 10px;
        }
        .delta {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 8px;
            font-size: 0.88rem;
            color: var(--success);
        }
        .delta.is-down {
            color: var(--warning);
        }
        .columns {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 16px;
        }
        .panel {
            background: linear-gradient(180deg, var(--panel-strong), rgba(7, 17, 31, 0.92));
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 20px;
            box-shadow: var(--shadow);
        }
        .list {
            display: grid;
            gap: 12px;
            margin-top: 14px;
        }
        .list-item {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            padding: 14px 16px;
            border-radius: 16px;
            background: rgba(148, 163, 184, 0.06);
            border: 1px solid rgba(148, 163, 184, 0.08);
        }
        .list-item strong {
            display: block;
            margin-bottom: 3px;
        }
        .list-item span {
            color: var(--muted);
            font-size: 0.92rem;
        }
        .channel-card {
            padding: 18px;
            border-radius: 20px;
            background: linear-gradient(180deg, var(--panel), rgba(7, 17, 31, 0.9));
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }
        .channel-card strong {
            display: block;
            font-size: 1.05rem;
            margin-bottom: 8px;
        }
        .channel-card .big {
            font-size: 1.9rem;
            font-weight: 800;
            margin: 10px 0 8px;
        }
        .foot {
            margin-top: 26px;
            color: var(--muted);
            font-size: 0.95rem;
        }
        .muted {
            color: var(--muted);
        }
        a { color: var(--accent); }
        code {
            color: var(--accent);
            background: rgba(56, 189, 248, 0.1);
            padding: 0.12rem 0.32rem;
            border-radius: 6px;
        }
        @media (max-width: 900px) {
            .hero,
            .topbar,
            .section-head,
            .list-item {
                flex-direction: column;
                align-items: flex-start;
            }
            .columns {
                grid-template-columns: 1fr;
            }
            .objective-layout,
            .objective-kpis {
                grid-template-columns: 1fr;
            }
            .nav {
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
    <main class="shell">
        <header class="topbar">
            <div class="brand">
                <div class="mark">HM</div>
                <div>
                    <strong>Hurricane Music</strong>
                    <span>Dashboard de pilotage interne</span>
                </div>
            </div>
            <nav class="nav" aria-label="Navigation principale">
                <a href="#overview">Accueil</a>
                <a href="#sales">Ventes</a>
                <a href="#marketing">Marketing</a>
                <a href="#stock">Stock</a>
                <a href="#performance">Performance</a>
            </nav>
        </header>
        <?= $content ?>
    </main>
</body>
</html>
