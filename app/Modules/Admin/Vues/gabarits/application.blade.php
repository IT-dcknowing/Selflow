<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titre', 'Tableau de bord') — Selflow</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --sidebar-w: 260px;
            --topbar-h: 64px;
            --primary:   #002B5C; /* Bleu royal profond */
            --primary-d: #001F42; /* Bleu plus foncé pour hover/active */
            --success:   #10b981;
            --warning:   #f59e0b;
            --danger:    #ef4444;
            --info:      #3b82f6;
            --bg:        #F4F6F9; /* Fond général gris-bleu très clair */
            --bg2:       #ffffff; /* Fond topbar et cartes */
            --bg3:       #EBF2FC; /* Fond actif/hover, en-têtes de table */
            --surface:   #ffffff; /* Cartes et modaux */
            --border:    #E2E8F0; /* Bordures claires */
            --text:      #1E293B; /* Texte sombre (slate-800) */
            --text-2:    #475569; /* Texte secondaire (slate-600) */
            --text-3:    #94a3b8; /* Texte atténué (slate-400) */
            --radius:    12px;
            --shadow:    0 10px 30px rgba(0, 0, 0, 0.05); /* Ombre douce */
        }

        @php
            $lectureSeule = session()->has('apercu_pdv_id');
        @endphp

        body {
            --banner-h: {{ $lectureSeule ? '40px' : '0px' }};
        }

        html, body { height: 100%; font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); font-size: 14px; }

        /* ── SIDEBAR ─────────────────────────────── */
        .sidebar {
            position: fixed; top: 0; left: 0; width: var(--sidebar-w); height: 100vh;
            background: #002B5C; border-right: none;
            display: flex; flex-direction: column; z-index: 100;
            overflow-y: auto; overflow-x: hidden;
            transition: transform 0.3s ease, width 0.3s ease;
        }
        .sidebar-logo {
            padding: 24px 20px 20px;
            display: flex; align-items: center; gap: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .sidebar-logo .logo-icon {
            width: 40px; height: 40px; border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; font-weight: 800; color: #fff; letter-spacing: -1px;
            flex-shrink: 0;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .sidebar-logo .logo-text { font-size: 18px; font-weight: 700; color: #ffffff; }
        .sidebar-logo .logo-sub  { font-size: 11px; color: rgba(255, 255, 255, 0.6); margin-top: 1px; }

        .sidebar-pdv {
            margin: 14px 12px 0;
            background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.15);
            border-radius: 10px; padding: 10px 14px; font-size: 12px;
        }
        .sidebar-pdv .pdv-label { color: rgba(255, 255, 255, 0.5); margin-bottom: 3px; text-transform: uppercase; letter-spacing:.5px; font-size: 10px; }
        .sidebar-pdv .pdv-name  { color: #FFC107; font-weight: 600; }

        .sidebar-nav { flex: 1; padding: 16px 12px; display: flex; flex-direction: column; gap: 2px; }

        .nav-section { margin-top: 16px; margin-bottom: 6px; }
        .nav-section span {
            font-size: 10px; font-weight: 600; letter-spacing: .8px;
            text-transform: uppercase; color: rgba(255, 255, 255, 0.4); padding: 0 10px;
        }

        .nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px; border-radius: 8px; text-decoration: none;
            color: rgba(255, 255, 255, 0.75); font-weight: 500; font-size: 13.5px;
            transition: all .15s; position: relative;
        }
        .nav-item:hover { background: rgba(255, 255, 255, 0.08); color: #ffffff; }
        .nav-item.active {
            background: rgba(255, 255, 255, 0.15);
            color: #ffffff; border: 1px solid rgba(255, 255, 255, 0.25);
        }
        .nav-item.active i { color: #FFC107; }
        .nav-item i { width: 18px; text-align: center; font-size: 14px; }

        .nav-badge {
            margin-left: auto; background: var(--danger);
            color: #fff; font-size: 10px; font-weight: 700;
            padding: 2px 7px; border-radius: 20px; line-height: 1.4;
        }

        .sidebar-footer {
            padding: 16px 12px; border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        .sidebar-user {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 10px;
            background: rgba(255, 255, 255, 0.08);
        }
        .sidebar-user .avatar {
            width: 34px; height: 34px; border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 13px; color: #fff; flex-shrink: 0;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .sidebar-user .user-info .name  { font-size: 13px; font-weight: 600; color: #ffffff; }
        .sidebar-user .user-info .role  { font-size: 11px; color: rgba(255, 255, 255, 0.5); text-transform: capitalize; }
        .sidebar-user .logout-btn {
            margin-left: auto; color: rgba(255, 255, 255, 0.6); font-size: 15px;
            transition: color .15s; cursor: pointer; border: none; background: none;
        }
        .sidebar-user .logout-btn:hover { color: #fca5a5; }

        /* ── TOPBAR ──────────────────────────────── */
        .topbar {
            position: fixed; top: var(--banner-h); left: var(--sidebar-w); right: 0;
            height: var(--topbar-h); background: var(--bg2);
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; padding: 0 28px;
            gap: 16px; z-index: 90;
            transition: left 0.3s ease, top 0.3s ease;
        }
        .topbar-title { font-size: 16px; font-weight: 700; flex: 1; color: var(--text); }
        .topbar-title span { color: var(--text-2); font-weight: 400; font-size: 13px; margin-left: 6px; }

        .topbar-badge {
            display: flex; align-items: center; gap: 6px;
            background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.25);
            border-radius: 20px; padding: 5px 12px; font-size: 12px; color: #b91c1c;
        }

        .topbar-clock {
            font-size: 13px; color: var(--text-2);
            background: #F1F5F9; border-radius: 8px; padding: 6px 12px;
        }

        /* ── MAIN CONTENT ────────────────────────── */
        .main-wrap {
            margin-left: var(--sidebar-w);
            padding-top: calc(var(--topbar-h) + var(--banner-h));
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        /* Collapsed / Expanded states for sidebar */
        body.sidebar-collapsed {
            --sidebar-w: 0px;
        }
        body.sidebar-collapsed .sidebar {
            transform: translateX(-100%);
        }

        /* Hamburger button style */
        .toggle-sidebar-btn {
            background: none; border: none; font-size: 18px; color: var(--text);
            cursor: pointer; padding: 8px; display: flex; align-items: center;
            justify-content: center; transition: color .15s; margin-right: 12px;
            border-radius: 6px;
        }
        .toggle-sidebar-btn:hover { background: var(--bg3); color: var(--primary); }

        /* Banner aperçu */
        .banner-apercu {
            background: #FEF3C7; border-bottom: 1px solid #FCD34D; color: #92400E;
            padding: 8px 24px; display: flex; align-items: center; justify-content: space-between;
            position: fixed; top: 0; left: var(--sidebar-w); right: 0; height: 40px; z-index: 105;
            transition: left 0.3s ease; font-size: 13px;
        }
        .banner-content { display: flex; align-items: center; gap: 8px; }
        .banner-content i { font-size: 15px; color: #D97706; }
        .btn-quit-apercu {
            background: #D97706; color: #fff; border: none; padding: 6px 12px;
            border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; transition: background .15s;
        }
        .btn-quit-apercu:hover { background: #B45309; }
        .main-content { padding: 28px 32px; }

        /* ── ALERTS ──────────────────────────────── */
        .alert {
            padding: 14px 18px; border-radius: 10px; margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px; font-size: 13.5px;
        }
        .alert-success { background: #ECFDF5; border: 1px solid #A7F3D0; color: #065F46; }
        .alert-danger  { background: #FEF2F2; border: 1px solid #FEE2E2; color: #991B1B; }
        .alert-warning { background: #FFFBEB; border: 1px solid #FDE68A; color: #92400E; }

        /* ── TOAST (pop-up de notification / rejet FNE) ─────────
           Centré au plein milieu de l'écran (centre du dashboard). */
        .toast-zone {
            position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 4000;
            display: flex; flex-direction: column; align-items: center; gap: 14px;
            width: min(540px, calc(100vw - 32px)); pointer-events: none;
        }
        .toast {
            pointer-events: auto; width: 100%;
            display: flex; align-items: flex-start; gap: 15px;
            background: #fff; border-radius: 16px; padding: 20px 22px;
            box-shadow: 0 24px 64px rgba(15, 23, 42, .30), 0 4px 16px rgba(0, 0, 0, .08);
            border: 1px solid var(--border); border-left: 6px solid #94a3b8;
            animation: toast-in .32s cubic-bezier(.16, 1, .3, 1);
        }
        .toast.sortie { animation: toast-out .25s ease forwards; }
        .toast .ic { font-size: 24px; line-height: 1.2; margin-top: 1px; flex-shrink: 0; color: #94a3b8; }
        .toast .bd { flex: 1; min-width: 0; }
        .toast .ti { font-weight: 800; font-size: 13.5px; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 5px; }
        .toast .ms { font-size: 14px; color: var(--text-2); line-height: 1.6; word-break: break-word; }
        .toast .x  { background: none; border: none; cursor: pointer; color: var(--text-3);
                     font-size: 18px; line-height: 1; padding: 4px 6px; flex-shrink: 0; border-radius: 6px; }
        .toast .x:hover { color: var(--text-1); background: rgba(100, 116, 139, .12); }
        .toast-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
        .toast-action {
            display: inline-block; padding: 9px 18px; border-radius: 10px; cursor: pointer;
            font-size: 13px; font-weight: 700; text-decoration: none; color: #fff;
            background: #64748b; transition: filter .12s ease, transform .12s ease;
        }
        .toast-action:hover { filter: brightness(1.07); transform: translateY(-1px); }
        /* Le bouton principal (POST : lancer la correction) prend la couleur du
           type ; le secondaire (naviguer) reste en contour, plus discret. */
        .toast.t-succes        .toast-action { background: #10b981; }
        .toast.t-avertissement .toast-action { background: #f59e0b; }
        .toast.t-erreur        .toast-action { background: #ef4444; }
        .toast.t-info          .toast-action { background: #3b82f6; }
        .toast-action.secondaire {
            background: transparent; color: var(--text-2);
            border: 1px solid var(--border);
        }
        .toast-action.secondaire:hover { background: rgba(100, 116, 139, .10); color: var(--text-1); }
        .toast.t-succes        { border-left-color: #10b981; } .toast.t-succes .ic        { color: #10b981; } .toast.t-succes .ti        { color: #065f46; }
        .toast.t-avertissement { border-left-color: #f59e0b; } .toast.t-avertissement .ic { color: #f59e0b; } .toast.t-avertissement .ti { color: #92400e; }
        .toast.t-erreur        { border-left-color: #ef4444; } .toast.t-erreur .ic        { color: #ef4444; } .toast.t-erreur .ti        { color: #991b1b; }
        .toast.t-info          { border-left-color: #3b82f6; } .toast.t-info .ic          { color: #3b82f6; } .toast.t-info .ti          { color: #1e3a8a; }
        /* ── Liste sélective des points de vente dans le pop-up ── */
        .toast-pdv-list {
            display: flex; flex-direction: column; gap: 8px; margin-top: 14px;
            max-height: 250px; overflow-y: auto; padding-right: 4px; width: 100%;
        }
        .toast-pdv-item {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
            padding: 9px 12px; transition: all .15s ease;
        }
        .toast-pdv-item:hover {
            background: #f1f5f9; border-color: #cbd5e1;
        }
        .toast-pdv-name {
            display: flex; align-items: center; gap: 8px; font-weight: 700;
            font-size: 13.5px; color: #1e293b;
        }
        .toast-pdv-name i { color: #f59e0b; font-size: 13px; }
        .toast-pdv-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px; border-radius: 8px; cursor: pointer;
            font-size: 12.5px; font-weight: 700; text-decoration: none;
            color: #fff; background: #2563eb; border: none;
            box-shadow: 0 2px 6px rgba(37, 99, 235, .25);
            transition: all .15s ease; flex-shrink: 0;
        }
        .toast-pdv-btn:hover {
            background: #1d4ed8; transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(37, 99, 235, .35);
        }
        @keyframes toast-in  { from { opacity: 0; transform: scale(.90); } to { opacity: 1; transform: scale(1); } }
        @keyframes toast-out { to  { opacity: 0; transform: scale(.90); } }
        @media (max-width: 560px) { .toast-zone { top: 50%; left: 50%; transform: translate(-50%, -50%); width: calc(100vw - 28px); } }

        /* ── CARDS ───────────────────────────────── */
        .card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); box-shadow: var(--shadow);
        }
        .card-header {
            padding: 18px 22px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
        }
        .card-header h2 { font-size: 15px; font-weight: 700; color: var(--text); }
        .card-body { padding: 22px; }

        /* ── STAT CARDS ──────────────────────────── */
        .stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 18px; margin-bottom: 24px; }
        .stat-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 20px 22px;
            display: flex; align-items: center; gap: 16px;
            transition: transform .2s, box-shadow .2s;
            box-shadow: var(--shadow);
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,0,0,.08); }
        .stat-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; flex-shrink: 0;
        }
        .stat-icon.green  { background: rgba(16,185,129,.1); color: var(--success); }
        .stat-icon.red    { background: rgba(239,68,68,.1);  color: var(--danger);  }
        .stat-icon.blue   { background: rgba(59,130,246,.1); color: var(--info);    }
        .stat-icon.yellow { background: rgba(245,158,11,.1); color: var(--warning); }
        .stat-icon.purple { background: rgba(0,43,92,.1);    color: var(--primary); }
        .stat-value { font-size: 22px; font-weight: 800; line-height: 1.2; color: var(--text); }
        .stat-label { font-size: 12px; color: var(--text-2); margin-top: 3px; }
        .stat-change { font-size: 11px; margin-top: 5px; }
        .stat-change.up   { color: var(--success); }
        .stat-change.down { color: var(--danger); }

        /* ── TABLE ───────────────────────────────── */
        .table-wrap { overflow: auto; max-height: 70vh; border-radius: var(--radius); }
        table { width: 100%; border-collapse: collapse; }
        thead th {
            background: #F8FAFC; padding: 12px 16px; text-align: left;
            font-size: 11px; font-weight: 600; text-transform: uppercase;
            letter-spacing: .5px; color: var(--text-2); border-bottom: 1px solid var(--border);
            position: sticky; top: 0; z-index: 10; white-space: nowrap;
        }
        tbody tr { border-bottom: 1px solid var(--border); transition: background .12s; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: rgba(0,0,0,.02); }
        tbody td { padding: 13px 16px; font-size: 13px; vertical-align: middle; color: var(--text); white-space: nowrap; }

        /* ── BADGES ──────────────────────────────── */
        .badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600;
        }
        .badge-success { background: rgba(16,185,129,.1); color: #065f46; }
        .badge-warning { background: rgba(245,158,11,.1);  color: #92400e; }
        .badge-danger  { background: rgba(239,68,68,.1);   color: #991b1b; }
        .badge-info    { background: rgba(59,130,246,.1);  color: #1e3a8a; }
        .badge-purple  { background: rgba(0,43,92,.1);     color: #002b5c; }
        .badge-gray    { background: rgba(100,116,139,.1); color: #475569; }

        /* ── BUTTONS ─────────────────────────────── */
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 18px; border-radius: 8px; font-size: 13px;
            font-weight: 600; cursor: pointer; border: none; text-decoration: none;
            transition: all .15s; font-family: 'Inter', sans-serif;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-d); transform: translateY(-1px); }
        .btn-success { background: var(--success); color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-outline {
            background: transparent; color: var(--text-2);
            border: 1px solid var(--border);
        }
        .btn-outline:hover { background: #F1F5F9; color: var(--text); }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        /* ── FORMS ───────────────────────────────── */
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 12px; font-weight: 600; color: var(--text-2); margin-bottom: 6px; text-transform: uppercase; letter-spacing: .4px; }
        .form-control {
            width: 100%; background: #ffffff; border: 1px solid var(--border);
            border-radius: 8px; padding: 10px 14px; color: var(--text);
            font-size: 13px; font-family: 'Inter', sans-serif;
            transition: border-color .15s, box-shadow .15s; outline: none;
        }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0, 43, 92, 0.15); }
        .form-control::placeholder { color: var(--text-3); }
        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
        .is-invalid { border-color: var(--danger) !important; }
        .invalid-feedback { font-size: 12px; color: #dc2626; margin-top: 4px; }

        /* ── PAGINATION ──────────────────────────── */
        .pagination { display: flex; list-style: none; gap: 6px; justify-content: center; padding: 20px 0; margin: 0; }
        .pagination .page-link {
            background: var(--bg3); border: 1px solid var(--border);
            border-radius: 6px; padding: 7px 14px; font-size: 13px; color: var(--text-2);
            text-decoration: none; transition: all .15s;
        }
        .pagination .page-link:hover,
        .pagination .page-item.active .page-link {
            background: var(--primary); color: #fff; border-color: var(--primary);
        }

        /* Compatibilité sans framework CSS pour la pagination Laravel (Bootstrap 5) */
        nav.justify-content-between {
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            width: 100% !important;
        }
        nav.justify-content-between ul.pagination {
            display: flex !important;
            list-style: none !important;
            list-style-type: none !important;
            padding: 0 !important;
            margin: 0 !important;
        }
        nav.justify-content-between ul.pagination li {
            list-style: none !important;
            list-style-type: none !important;
            display: inline-block !important;
        }
        nav.justify-content-between div.d-sm-none {
            display: none !important;
        }
        nav.justify-content-between div.d-none {
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            gap: 8px !important;
        }
        nav.justify-content-between div.d-none > div.small,
        nav.justify-content-between div.d-none > div.text-muted {
            display: none !important;
        }

        /* ── GRID LAYOUTS ────────────────────────── */
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 22px; }
        .grid-3-1 { display: grid; grid-template-columns: 3fr 1fr; gap: 22px; }

        /* ── PAGE HEADER ─────────────────────────── */
        .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .page-header h1 { font-size: 22px; font-weight: 800; }
        .page-header p  { color: var(--text-2); margin-top: 4px; font-size: 13px; }

        /* ── ALERT STOCK ─────────────────────────── */
        .stock-alerte { color: var(--danger); font-weight: 600; }
        .stock-ok     { color: var(--success); }
        .stock-warning{ color: var(--warning); }

        /* ── MODAL ───────────────────────────────── */
        .modal-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,.6); backdrop-filter: blur(4px);
            z-index: 200; display: none; align-items: center; justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 16px; padding: 28px; width: 100%; max-width: 540px;
            box-shadow: 0 24px 64px rgba(0,0,0,.5); animation: modalIn .2s ease;
            max-height: 85vh; overflow-y: auto;
        }
        @keyframes modalIn { from { opacity:0; transform: scale(.96) translateY(8px); } to { opacity:1; transform: none; } }
        .modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 22px; }
        .modal-header h3 { font-size: 16px; font-weight: 700; }
        .modal-close { background: none; border: none; color: var(--text-3); font-size: 20px; cursor: pointer; }
        .modal-close:hover { color: var(--danger); }

        /* ── SCROLLBAR ───────────────────────────── */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--bg3); border-radius: 6px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-3); }

        @media (max-width: 1100px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); width: 260px !important; }
            .main-wrap { margin-left: 0 !important; }
            .topbar { left: 0 !important; }
            .banner-apercu { left: 0 !important; }
            body.sidebar-open .sidebar { transform: translateX(0); }
            .stats-grid { grid-template-columns: 1fr; }
        }

        /* ── Alerte de stickers ──
           Deployable, presente sur toutes les pages tant que le stock est bas.
           Elle n'est pas refermable definitivement : un stock epuise arrete la
           certification, ce n'est pas un detail qu'on ecarte d'un clic. */
        .alerte-stickers {
            margin: 0 0 18px;
            border-radius: 10px;
            border: 1px solid #FDE68A;
            background: #FFFBEB;
            color: #92400E;
            overflow: hidden;
        }
        .alerte-stickers.epuise {
            border-color: #FECACA;
            background: #FEF2F2;
            color: #991B1B;
        }
        .alerte-stickers > summary {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            list-style: none;
        }
        .alerte-stickers > summary::-webkit-details-marker { display: none; }
        .alerte-stickers > summary .chevron { margin-left: auto; transition: transform .15s; }
        .alerte-stickers[open] > summary .chevron { transform: rotate(180deg); }
        .alerte-stickers .detail {
            padding: 0 16px 14px 42px;
            font-size: 12.5px;
            font-weight: 400;
            line-height: 1.7;
        }
        .alerte-stickers .detail strong { font-weight: 700; }
    </style>
    @yield('styles')
</head>
<body>

@if(session()->has('apercu_pdv_id'))
<div class="banner-apercu">
    <div class="banner-content">
        <i class="fas fa-eye"></i>
        <span><strong>Mode Aperçu :</strong> Vous consultez l'interface caissier de <strong>{{ session('apercu_pdv_nom') }}</strong> en mode <strong>Lecture Seule</strong>. Les actions d'enregistrement et de modification sont désactivées.</span>
    </div>
    <form method="POST" action="{{ route('admin.pdv.desactiver_apercu') }}">
        @csrf
        <button type="submit" class="btn-quit-apercu">
            <i class="fas fa-right-from-bracket"></i> Quitter l'aperçu
        </button>
    </form>
</div>
@endif

<!-- ────────────────── SIDEBAR ────────────────── -->
<aside class="sidebar">
    <div class="sidebar-logo">
        <div class="logo-icon">S</div>
        <div>
            <div class="logo-text">Selflow</div>
            <div class="logo-sub">Gestion commerciale</div>
        </div>
    </div>

    @php
        $nomPdvAffichage = session('apercu_pdv_nom') ?? session('point_de_vente_actif_nom') ?? auth()->user()->pointDeVente?->nom;
        $estApercu = session()->has('apercu_pdv_id');
        $entreprise = auth()->user()?->entreprise;
        $modulesActifs = $entreprise?->modules_actifs;
        if (empty($modulesActifs)) {
            $modulesActifs = ['principal', 'ventes', 'achats', 'stock', 'production', 'comptabilite', 'points_de_vente', 'produits', 'tiers', 'rapports', 'b2b', 'fne'];
        }
        $secteurActivite = $entreprise?->secteur_activite ?? [];
        if (is_string($secteurActivite)) {
            $secteurActivite = [$secteurActivite];
        }
        // Le résultat par site n'a de sens qu'à partir de deux sites. Le
        // compte est fait une fois ici plutôt qu'à chaque lien du menu.
        $nombreDeSites = $entreprise
            ? \App\Modules\Admin\Modeles\PointDeVente::where('entreprise_id', $entreprise->id)->count()
            : 0;
    @endphp

    @if($nomPdvAffichage)
    <div class="sidebar-pdv">
        <div class="pdv-label">
            <i class="fas fa-store"></i> 
            @if($estApercu)
                Aperçu Point de Vente
            @else
                Point de Vente
            @endif
        </div>
        <div class="pdv-name">{{ $nomPdvAffichage }}</div>
    </div>
    @endif

    @if(!request()->routeIs('superadmin.*') && auth()->user()->role !== 'superadmin')
    <!-- Sélecteur d'exercice/période -->
    <div class="sidebar-periode" style="margin: 10px 12px 0; position: relative;">
        <button onclick="togglePeriodeDropdown(event)" style="width: 100%; display: flex; align-items: center; gap: 10px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15); border-radius: 10px; padding: 10px 14px; color: #ffffff; cursor: pointer; text-align: left; transition: all 0.2s; outline: none;">
            <i class="far fa-calendar-alt" style="font-size: 16px; color: #FFC107;"></i>
            <div style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                <div style="font-size: 10px; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 0.5px;">Période en cours</div>
                <div style="font-size: 13px; font-weight: 600;">{{ session('active_periode_nom', 'Non défini') }}</div>
            </div>
            <i class="fas fa-chevron-down" style="font-size: 10px; color: rgba(255,255,255,0.6);"></i>
        </button>

        <div id="periodeDropdownMenu" style="display: none; position: absolute; left: 0; right: 0; top: calc(100% + 6px); background: #ffffff; border: 1px solid var(--border); border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.25); z-index: 1000; padding: 6px 0;">
            <div style="padding: 6px 14px 10px; border-bottom: 1px solid var(--border); font-size: 11px; font-weight: 600; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.5px;">
                Changer de période
            </div>
            <div style="max-height: 180px; overflow-y: auto;">
                @if(isset($global_periodes) && $global_periodes->count() > 0)
                    @php
                        $sortedPeriodes = $global_periodes->sortByDesc('date_debut');
                    @endphp
                    @foreach($sortedPeriodes as $p)
                        <form method="POST" action="{{ route('admin.periods.switch') }}" style="margin: 0;">
                            @csrf
                            <input type="hidden" name="periode_id" value="{{ $p->id }}">
                            <button type="submit" style="width: 100%; display: flex; align-items: center; justify-content: space-between; padding: 8px 14px; border: none; background: none; text-align: left; cursor: pointer; color: var(--text); font-family: inherit; font-size: 12.5px; transition: background 0.15s;" onmouseover="this.style.background='#F1F5F9'" onmouseout="this.style.background='none'">
                                <span>{{ $p->nom }}</span>
                                @if(session('active_periode_id') == $p->id)
                                    <span class="badge badge-success" style="padding: 2px 6px; font-size: 9px;">Actif</span>
                                @endif
                            </button>
                        </form>
                    @endforeach
                @else
                    <div style="padding: 10px 14px; font-size: 12px; color: var(--text-3); text-align: center;">Aucun exercice configuré</div>
                @endif
            </div>
            @if(auth()->user()->role === 'admin')
                <div style="border-top: 1px solid var(--border); margin: 6px 0 4px;"></div>
                <a href="{{ route('admin.entreprise.parametres') }}" style="display: flex; align-items: center; gap: 8px; padding: 8px 14px; font-size: 12px; color: var(--primary); font-weight: 600; text-decoration: none; transition: background 0.15s;" onmouseover="this.style.background='#F1F5F9'" onmouseout="this.style.background='none'">
                    <i class="fas fa-sliders" style="font-size: 11px;"></i> Gérer les exercices
                </a>
            @endif
        </div>
    </div>
    @endif

    <nav class="sidebar-nav">
        @if(request()->routeIs('superadmin.*') || auth()->user()->role === 'superadmin')
            <!-- ── SUPERADMIN SIDEBAR ── -->
            <div class="nav-section"><span>TABLEAU DE BORD</span></div>
            <a href="{{ route('superadmin.tableau_de_bord') }}" class="nav-item {{ request()->routeIs('superadmin.tableau_de_bord') ? 'active' : '' }}">
                <i class="fas fa-chart-pie"></i> Tableau de bord
            </a>
            
            <div class="nav-section"><span>SUPERVISION</span></div>
            <a href="{{ route('superadmin.entreprises') }}" class="nav-item {{ request()->routeIs('superadmin.entreprises*') ? 'active' : '' }}">
                <i class="fas fa-building"></i> Entreprises
            </a>
            <a href="{{ route('superadmin.utilisateurs') }}" class="nav-item {{ request()->routeIs('superadmin.utilisateurs*') ? 'active' : '' }}">
                <i class="fas fa-users-gear"></i> Habilitations &amp; Accès
            </a>
             @if(auth()->user()->aHabilitation('administration_interne'))
            <a href="{{ route('superadmin.admins.index') }}" class="nav-item {{ request()->routeIs('superadmin.admins*') ? 'active' : '' }}">
                <i class="fas fa-user-shield"></i> Admins Internes
            </a>
            @endif

            <div class="nav-section"><span>INTÉGRATIONS</span></div>
            <a href="{{ route('superadmin.liaisons.index') }}" class="nav-item {{ request()->routeIs('superadmin.liaisons*') ? 'active' : '' }}">
                <i class="fas fa-link"></i> Liaisons COMPTAFLOW
            </a>
            <a href="{{ route('superadmin.fne.index') }}" class="nav-item {{ request()->routeIs('superadmin.fne*') ? 'active' : '' }}">
                <i class="fas fa-key"></i> Gestion FNE
            </a>

            @if(auth()->user()->aHabilitation('gestion_secteurs_modules'))
            <div class="nav-section"><span>CONFIGURATION</span></div>
            <a href="{{ route('superadmin.secteurs_modules.index') }}" class="nav-item {{ request()->routeIs('superadmin.secteurs_modules*') ? 'active' : '' }}">
                <i class="fas fa-cubes"></i> Secteurs &amp; Modules
            </a>
            <a href="{{ route('superadmin.referentiel.index') }}" class="nav-item {{ request()->routeIs('superadmin.referentiel*') ? 'active' : '' }}">
                <i class="fas fa-book-open"></i> Référentiel d'activités
            </a>
            @endif

            @if(auth()->user()->aHabilitation('gestion_vitrine'))
            <a href="{{ route('superadmin.vitrine.index') }}" class="nav-item {{ request()->routeIs('superadmin.vitrine*') ? 'active' : '' }}">
                <i class="fas fa-window-maximize"></i> Vitrine publique
            </a>
            @endif
        @elseif(request()->routeIs('caissier.*'))
            <!-- ── CAISSIER SIDEBAR ── -->
            <div class="nav-section"><span>Caisse</span></div>
            <a href="{{ route('caissier.ventes.nouvelle') }}" class="nav-item {{ request()->routeIs('caissier.ventes.nouvelle') ? 'active' : '' }}">
                <i class="fas fa-cash-register"></i> Nouvelle vente
            </a>
            <a href="{{ route('caissier.ventes.factures') }}" class="nav-item {{ request()->routeIs('caissier.ventes.factures') ? 'active' : '' }}">
                <i class="fas fa-file-invoice"></i> Mes factures
            </a>

            <div class="nav-section"><span>Stock</span></div>
            <a href="{{ route('caissier.stock.index') }}" class="nav-item {{ request()->routeIs('caissier.stock.index') ? 'active' : '' }}">
                <i class="fas fa-boxes-stacked"></i> Consulter stock
            </a>

            <div class="nav-section"><span>Trésorerie</span></div>
            <a href="{{ route('caissier.tresorerie.encaissements') }}" class="nav-item {{ request()->routeIs('caissier.tresorerie.encaissements') ? 'active' : '' }}">
                <i class="fas fa-arrow-down" style="color:#10b981;"></i> Mes encaissements
            </a>
        @else
            <!-- ── ADMIN SIDEBAR RESTUCTURÉ ── -->
            
            <!-- 1. Principal & Tableaux de bord -->
            @if(in_array('principal', $modulesActifs) && (auth()->user()->aHabilitation('tableau_de_bord_personnel') || auth()->user()->aHabilitation('tableau_de_bord_general')))
            <div class="nav-section"><span>Tableau de bord</span></div>
            @if(auth()->user()->aHabilitation('tableau_de_bord_personnel'))
            <a href="{{ route('admin.tableau_de_bord') }}" class="nav-item {{ request()->routeIs('admin.tableau_de_bord') ? 'active' : '' }}">
                <i class="fas fa-chart-pie"></i> TDB Personnel
            </a>
            @endif
            @if(auth()->user()->aHabilitation('tableau_de_bord_general'))
            <a href="{{ route('admin.tableau_de_bord_general') }}" class="nav-item {{ request()->routeIs('admin.tableau_de_bord_general') ? 'active' : '' }}">
                <i class="fas fa-chart-line"></i> TDB Général
            </a>
            @endif
            @endif

            <!-- 2. Ventes -->
            @if(in_array('ventes', $modulesActifs) && (auth()->user()->aHabilitation('nouvelle_vente') || auth()->user()->aHabilitation('factures_vente') || auth()->user()->aHabilitation('historique_ventes')))
            <div class="nav-section"><span>Ventes</span></div>
            @if(auth()->user()->aHabilitation('nouvelle_vente'))
            <a href="{{ route('admin.ventes.nouvelle') }}" data-visite="nouvelle-vente" class="nav-item {{ request()->routeIs('admin.ventes.nouvelle') ? 'active' : '' }}">
                <i class="fas fa-cash-register"></i> Nouvelle vente
            </a>
            @endif
            @if(auth()->user()->aHabilitation('factures_vente'))
            <a href="{{ route('admin.ventes.factures') }}" class="nav-item {{ request()->routeIs('admin.ventes.factures') && !request()->has('type') ? 'active' : '' }}">
                <i class="fas fa-file-invoice"></i> Factures vente
            </a>
            <a href="{{ route('admin.ventes.factures', ['type' => 'avoir']) }}" class="nav-item {{ request()->routeIs('admin.ventes.factures') && request('type') === 'avoir' ? 'active' : '' }}">
                <i class="fas fa-file-circle-minus" style="color:#e17055;"></i> Avoirs clients
            </a>
            @endif

            @if(auth()->user()->aHabilitation('nouvelle_vente'))
            <a href="{{ route('admin.b2b.negociations.fournisseur') }}" class="nav-item {{ request()->routeIs('admin.b2b.negociations.fournisseur*') ? 'active' : '' }}">
                <i class="fas fa-handshake"></i> Demandes B2B reçues
            </a>
            @endif
            @endif

            <!-- 3. Achats -->
            @if(in_array('achats', $modulesActifs) && (auth()->user()->aHabilitation('nouvel_achat') || auth()->user()->aHabilitation('factures_achat') || auth()->user()->aHabilitation('historique_achats')))
            <div class="nav-section"><span>Achats</span></div>
            @if(auth()->user()->aHabilitation('nouvel_achat'))
            <a href="{{ route('admin.achats.nouveau') }}" class="nav-item {{ request()->routeIs('admin.achats.nouveau') ? 'active' : '' }}">
                <i class="fas fa-cart-plus"></i> Nouvel achat
            </a>
            @endif
            @if(auth()->user()->aHabilitation('factures_achat'))
            <a href="{{ route('admin.achats.factures') }}" class="nav-item {{ request()->routeIs('admin.achats.factures') && !request()->has('type') ? 'active' : '' }}">
                <i class="fas fa-file-invoice-dollar"></i> Factures achat
            </a>
            <a href="{{ route('admin.achats.factures', ['type' => 'avoir']) }}" class="nav-item {{ request()->routeIs('admin.achats.factures') && request('type') === 'avoir' ? 'active' : '' }}">
                <i class="fas fa-file-circle-minus" style="color:#e17055;"></i> Avoirs fournisseurs
            </a>
            <a href="{{ route('admin.achats.factures_recues') }}" class="nav-item {{ request()->routeIs('admin.achats.factures_recues*') ? 'active' : '' }}">
                <i class="fas fa-inbox"></i> Factures re&ccedil;ues (portail FNE)
            </a>
            @endif

            @if(auth()->user()->aHabilitation('nouvel_achat'))
            <a href="{{ route('admin.b2b.negociations.client') }}" class="nav-item {{ request()->routeIs('admin.b2b.negociations.client*') ? 'active' : '' }}">
                <i class="fas fa-comments-dollar"></i> Négociations B2B (Achats)
            </a>
            @endif
            @endif

            <!-- 4. Stock -->
            @if(in_array('stock', $modulesActifs) && (auth()->user()->aHabilitation('stock_articles') || auth()->user()->aHabilitation('stock_mouvements')))
            <div class="nav-section"><span>Stock</span></div>
            @if(auth()->user()->aHabilitation('stock_articles'))
            <a href="{{ route('admin.stock.index') }}" class="nav-item {{ request()->routeIs('admin.stock.index') ? 'active' : '' }}">
                <i class="fas fa-boxes-stacked"></i> Articles & stock
            </a>
            @endif
            @if(auth()->user()->aHabilitation('stock_mouvements'))
            <a href="{{ route('admin.stock.mouvements') }}" class="nav-item {{ request()->routeIs('admin.stock.mouvements') ? 'active' : '' }}">
                <i class="fas fa-arrows-up-down"></i> Mouvements
            </a>
            <a href="{{ route('admin.stock.inventaire') }}" class="nav-item {{ request()->routeIs('admin.stock.inventaire*') ? 'active' : '' }}">
                <i class="fas fa-clipboard-check"></i> Inventaire physique
            </a>
            @endif
            @endif
            <!-- 5. Production -->
            @if(in_array('production', $modulesActifs) && (auth()->user()->aHabilitation('catalogue_produits') || auth()->user()->aHabilitation('stock_articles')))
            <div class="nav-section"><span>Production</span></div>
            @if(auth()->user()->aHabilitation('catalogue_produits'))
            <a href="{{ route('admin.production.fiches_techniques.index') }}" class="nav-item {{ request()->routeIs('admin.production.fiches_techniques*') ? 'active' : '' }}">
                <i class="fas fa-flask"></i> Recettes (FT)
            </a>
            @endif
            @if(auth()->user()->aHabilitation('stock_articles'))
            <a href="{{ route('admin.production.ordres.index') }}" class="nav-item {{ request()->routeIs('admin.production.ordres*') ? 'active' : '' }}">
                <i class="fas fa-industry"></i> Ordres de production
            </a>
            @endif
            @endif

            <!-- 6. Comptabilité (Inclus Trésorerie) -->
            @if(in_array('comptabilite', $modulesActifs) && (auth()->user()->aHabilitation('tresorerie_encaissements') || auth()->user()->aHabilitation('tresorerie_decaissements') || auth()->user()->aHabilitation('tresorerie_journal') || auth()->user()->aHabilitation('tresorerie_codes_journaux') || auth()->user()->aHabilitation('comptabilite_globale') || auth()->user()->aHabilitation('comptabilite_creances') || auth()->user()->aHabilitation('comptabilite_plan_comptable')))
            <div class="nav-section"><span>Comptabilité</span></div>
            @if(auth()->user()->aHabilitation('tresorerie_encaissements'))
            <a href="{{ route('admin.tresorerie.encaissements') }}" class="nav-item {{ request()->routeIs('admin.tresorerie.encaissements') ? 'active' : '' }}">
                <i class="fas fa-arrow-down" style="color:#10b981;"></i> Encaissements
            </a>
            @endif
            @if(auth()->user()->aHabilitation('tresorerie_decaissements'))
            <a href="{{ route('admin.tresorerie.decaissements') }}" class="nav-item {{ request()->routeIs('admin.tresorerie.decaissements') ? 'active' : '' }}">
                <i class="fas fa-arrow-up" style="color:#ef4444;"></i> Décaissements
            </a>
            @endif
            @if(auth()->user()->aHabilitation('tresorerie_journal'))
            <a href="{{ route('admin.tresorerie.journal') }}" class="nav-item {{ request()->routeIs('admin.tresorerie.journal') ? 'active' : '' }}">
                <i class="fas fa-wallet"></i> Solde &amp; journal
            </a>
            @endif
            @if(auth()->user()->aHabilitation('tresorerie_codes_journaux'))
            <a href="{{ route('admin.tresorerie.codes_journaux') }}" class="nav-item {{ request()->routeIs('admin.tresorerie.codes_journaux') ? 'active' : '' }}">
                <i class="fas fa-book"></i> Codes Journaux
            </a>
            @endif
            @if(auth()->user()->aHabilitation('comptabilite_globale'))
            <a href="{{ route('admin.comptabilite.globale') }}" class="nav-item {{ request()->routeIs('admin.comptabilite.globale') ? 'active' : '' }}">
                <i class="fas fa-list-check"></i> Opération &amp; écriture globale
            </a>
            @endif
            @if(auth()->user()->aHabilitation('comptabilite_creances'))
            <a href="{{ route('admin.comptabilite.creances') }}" class="nav-item {{ request()->routeIs('admin.comptabilite.creances') ? 'active' : '' }}">
                <i class="fas fa-scale-balanced"></i> Créances &amp; règlements
            </a>
            @endif
            @if(auth()->user()->aHabilitation('comptabilite_plan_comptable'))
            <a href="{{ route('admin.comptabilite.plan_comptable') }}" class="nav-item {{ request()->routeIs('admin.comptabilite.plan_comptable') ? 'active' : '' }}">
                <i class="fas fa-book-open"></i> Plan Comptable
            </a>
            @endif
            @if(auth()->user()->aHabilitation('comptabilite_globale'))
            <a href="{{ route('admin.comptabilite.balance') }}" class="nav-item {{ request()->routeIs('admin.comptabilite.balance') ? 'active' : '' }}">
                <i class="fas fa-scale-balanced"></i> Balance de contrôle
            </a>
            <a href="{{ route('admin.comptabilite.grand_livre') }}" class="nav-item {{ request()->routeIs('admin.comptabilite.grand_livre') ? 'active' : '' }}">
                <i class="fas fa-book-open"></i> Grand livre
            </a>
            <a href="{{ route('admin.comptabilite.lettrage') }}" class="nav-item {{ request()->routeIs('admin.comptabilite.lettrage') ? 'active' : '' }}">
                <i class="fas fa-link"></i> Lettrage
            </a>
            {{-- La ventilation analytique n'a de sens qu'à plusieurs sites :
                 comparer un magasin à lui-même n'apprend rien, et le lien
                 encombrerait le menu d'un commerce qui n'en a qu'un. --}}
            @if(($nombreDeSites ?? 0) > 1)
            <a href="{{ route('admin.comptabilite.analytique') }}" class="nav-item {{ request()->routeIs('admin.comptabilite.analytique') ? 'active' : '' }}">
                <i class="fas fa-store"></i> Résultat par site
            </a>
            @endif
            <a href="{{ route('admin.comptabilite.libelles') }}" class="nav-item {{ request()->routeIs('admin.comptabilite.libelles') ? 'active' : '' }}">
                <i class="fas fa-pen-nib"></i> Libellés d'écriture
            </a>
            @endif
            @endif

            <!-- Fiscalité & DGI (Module FNE) -->
            @if(in_array('comptabilite', $modulesActifs))
            <div class="nav-section"><span>Fiscalité &amp; DGI</span></div>
            <a href="{{ route('admin.fne.gestion') }}" data-visite="fne" class="nav-item {{ request()->routeIs('admin.fne.gestion') ? 'active' : '' }}">
                <i class="fas fa-calculator"></i> Gestion FNE
            </a>
            <a href="{{ route('admin.fne.situation') }}" class="nav-item {{ request()->routeIs('admin.fne.situation') ? 'active' : '' }}">
                <i class="fas fa-chart-line"></i> Situation Générale
            </a>
            <a href="{{ route('admin.fne.factures') }}" class="nav-item {{ request()->routeIs('admin.fne.factures') ? 'active' : '' }}">
                <i class="fas fa-receipt"></i> Factures &amp; Reçus émis/reçus
            </a>
            @if(in_array('achats', $modulesActifs))
            <a href="{{ route('admin.achats.factures_recues') }}" class="nav-item {{ request()->routeIs('admin.achats.factures_recues*') ? 'active' : '' }}">
                <i class="fas fa-inbox"></i> Factures re&ccedil;ues du portail
            </a>
            @endif
            <a href="{{ route('admin.fne.stickers') }}" class="nav-item {{ request()->routeIs('admin.fne.stickers') ? 'active' : '' }}">
                <i class="fas fa-ticket"></i> Gestion des stickers
            </a>
            @php
                // Le compte des pièces refusées se lit ici et non dans un
                // composeur de vue : le gabarit calcule déjà le reste de sa barre
                // de la même façon. L'index (entreprise_id, statut) rend le
                // décompte négligeable, et sans ce chiffre un refus survenu la
                // nuit n'appellerait personne — l'écran ne se visite pas au hasard.
                $rejetsFneOuverts = $entreprise
                    ? \App\Modules\Admin\Modeles\FneRejet::where('entreprise_id', $entreprise->id)
                        ->whereIn('statut', ['ouvert', 'diagnostique'])->count()
                    : 0;
            @endphp
            <a href="{{ route('admin.fne.rejets') }}" class="nav-item {{ request()->routeIs('admin.fne.rejets') ? 'active' : '' }}">
                <i class="fas fa-triangle-exclamation"></i> Pièces refusées
                @if($rejetsFneOuverts > 0)
                <span style="background:#E53E3E;color:#fff;border-radius:20px;padding:1px 7px;font-size:10px;margin-left:6px;font-weight:700;">{{ $rejetsFneOuverts }}</span>
                @endif
            </a>
            @endif

            <!-- 6. Points de vente (Inclus Personnel & Habilitations) -->
            @if(in_array('points_de_vente', $modulesActifs) && (auth()->user()->aHabilitation('gestion_pdv') || auth()->user()->aHabilitation('gestion_personnel') || auth()->user()->aHabilitation('gestion_habilitations')))
            <div class="nav-section"><span>Points de vente</span></div>
            @if(auth()->user()->aHabilitation('gestion_pdv'))
            <a href="{{ route('admin.pdv.index') }}" class="nav-item {{ request()->routeIs('admin.pdv.index') ? 'active' : '' }}">
                <i class="fas fa-store"></i> Points de vente
            </a>
            @endif
            @if(auth()->user()->aHabilitation('gestion_personnel'))
            <a href="{{ route('admin.personnel.index') }}" class="nav-item {{ request()->routeIs('admin.personnel.index') && !request('tab') ? 'active' : '' }}">
                <i class="fas fa-users-gear"></i> Personnels &amp; accès
            </a>
            @endif
            @if(auth()->user()->aHabilitation('gestion_habilitations'))
            <a href="{{ route('admin.personnel.index') }}?tab=habilitations" class="nav-item {{ request()->routeIs('admin.personnel.index') && request('tab') === 'habilitations' ? 'active' : '' }}">
                <i class="fas fa-shield-halved"></i> Habilitations
            </a>
            @endif
            @endif

            <!-- 7. Produits (catalogue) -->
            @if((in_array('ventes', $modulesActifs) || in_array('achats', $modulesActifs) || in_array('stock', $modulesActifs)) && auth()->user()->aHabilitation('catalogue_produits'))
            <div class="nav-section"><span>Produits</span></div>
            <a href="{{ route('admin.produits.index') }}" data-visite="catalogue" class="nav-item {{ request()->routeIs('admin.produits.index') ? 'active' : '' }}">
                <i class="fas fa-barcode"></i> Catalogue produits
            </a>
            @endif

            <!-- 8. Tiers (Clients & Fournisseurs) -->
            @if((in_array('ventes', $modulesActifs) || in_array('achats', $modulesActifs)) && (auth()->user()->aHabilitation('tiers_clients') || auth()->user()->aHabilitation('tiers_fournisseurs')))
            <div class="nav-section"><span>Tiers</span></div>
            @if(auth()->user()->aHabilitation('tiers_clients'))
            <a href="{{ route('admin.clients.index') }}" data-visite="clients" class="nav-item {{ request()->routeIs('admin.clients.index') ? 'active' : '' }}">
                <i class="fas fa-users"></i> Clients
            </a>
            @endif
            @if(auth()->user()->aHabilitation('tiers_fournisseurs'))
            <a href="{{ route('admin.fournisseurs.index') }}" class="nav-item {{ request()->routeIs('admin.fournisseurs.index') ? 'active' : '' }}">
                <i class="fas fa-handshake"></i> Fournisseurs
            </a>
            @endif
            @endif

            <!-- 9. Rapports -->
            @if(in_array('principal', $modulesActifs) && auth()->user()->aHabilitation('rapports_analyse'))
            <div class="nav-section"><span>Rapports</span></div>
            <a href="{{ route('admin.rapports.analyse_activite') }}" class="nav-item {{ request()->routeIs('admin.rapports.analyse_activite') ? 'active' : '' }}">
                <i class="fas fa-chart-line"></i> Analyse d'activité
            </a>
            @endif

            <!-- 10. Paramètres entreprise (admin uniquement) -->
            @if(auth()->user()->role === 'admin')
            <div class="nav-section"><span>Entreprise</span></div>
            <a href="{{ route('admin.entreprise.parametres') }}" data-visite="parametres" class="nav-item {{ request()->routeIs('admin.entreprise.parametres') ? 'active' : '' }}">
                <i class="fas fa-gear"></i> Paramètres &amp; logos
            </a>
            @endif

        @endif
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="avatar">{{ strtoupper(substr(auth()->user()->nom, 0, 1)) }}</div>
            <div class="user-info">
                <div class="name">{{ auth()->user()->nom }}</div>
                <div class="role">{{ auth()->user()->role }}</div>
            </div>
            <form method="POST" action="{{ route('deconnexion') }}">
                @csrf
                <button type="submit" class="logout-btn" title="Se déconnecter">
                    <i class="fas fa-right-from-bracket"></i>
                </button>
            </form>
        </div>
    </div>
</aside>

<!-- ────────────────── TOPBAR ────────────────── -->
<header class="topbar">
    <button class="toggle-sidebar-btn" id="toggleSidebar" aria-label="Menu principal">
        <i class="fas fa-bars"></i>
    </button>
    <div class="topbar-title">
        @yield('topbar_titre', 'Tableau de bord')
        <span>/ {{ auth()->user()->entreprise->nom ?? 'Selflow' }} — {{ session('apercu_pdv_nom') ?? session('point_de_vente_actif_nom') ?? auth()->user()->pointDeVente?->nom ?? 'Siège' }}</span>
    </div>

    <div style="display:flex; align-items:center; gap:16px;">
        <div class="topbar-clock" id="horloge">--:--:--</div>
        
        {{-- Bouton Profil et Menu Déroulant (Images 2 et 3) --}}
        <div class="user-dropdown" style="position:relative; display:inline-block;">
            <button class="user-dropdown-btn" onclick="toggleUserDropdown()" style="display:flex; align-items:center; gap:8px; background:#ffffff; border:1px solid var(--border); border-radius:30px; padding:4px 14px 4px 4px; cursor:pointer; font-family:inherit; transition: all 0.15s; outline:none;">
                <img src="{{ auth()->user()->avatar_url }}" alt="Avatar" style="width:30px; height:30px; border-radius:50%; object-fit:cover; border:1px solid var(--border);">
                <span style="font-size:12.5px; font-weight:600; color:var(--primary); max-width:120px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                    {{ auth()->user()->prenom }} {{ auth()->user()->nom }}
                </span>
                <i class="fas fa-chevron-down" style="font-size:9px; color:var(--text-3);"></i>
            </button>
            
            <div class="user-dropdown-menu" id="userDropdownMenu" style="display:none; position:absolute; right:0; top:calc(100% + 8px); width:230px; background:#ffffff; border:1px solid var(--border); border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,0.08); z-index:1000; padding:6px 0;">
                <div style="padding:10px 14px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:10px;">
                    <img src="{{ auth()->user()->avatar_url }}" alt="Avatar" style="width:36px; height:36px; border-radius:50%; object-fit:cover; border:1px solid var(--border);">
                    <div style="overflow:hidden;">
                        <div style="font-weight:700; font-size:12.5px; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ auth()->user()->prenom }} {{ auth()->user()->nom }}</div>
                        <div style="font-size:11px; font-weight:600; color:var(--text-2); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-transform:capitalize;">
                            {{ auth()->user()->fonction ?: str_replace('_', ' ', auth()->user()->role) }}
                        </div>
                    </div>
                </div>
                <a href="{{ route('admin.mon_profil') }}" style="display:flex; align-items:center; gap:8px; padding:8px 14px; font-size:12.5px; color:var(--text); text-decoration:none; transition:background 0.15s;" onmouseover="this.style.background='#F1F5F9'" onmouseout="this.style.background='none'">
                    <i class="far fa-user" style="width:14px; color:var(--text-2);"></i> Mon profil
                </a>
                <a href="{{ route('admin.entreprise.parametres') }}" style="display:flex; align-items:center; gap:8px; padding:8px 14px; font-size:12.5px; color:var(--text); text-decoration:none; transition:background 0.15s;" onmouseover="this.style.background='#F1F5F9'" onmouseout="this.style.background='none'">
                    <i class="fas fa-gear" style="width:14px; color:var(--text-2);"></i> Paramètres
                </a>
                <a href="{{ route('admin.entreprise.parametres') }}" style="display:flex; align-items:center; gap:8px; padding:8px 14px; font-size:12.5px; color:var(--text); text-decoration:none; transition:background 0.15s;" onmouseover="this.style.background='#F1F5F9'" onmouseout="this.style.background='none'">
                    <i class="far fa-credit-card" style="width:14px; color:var(--text-2);"></i> Facturation
                </a>
                {{-- Revoir la visite guidée : un formulaire, pas un script, pour
                     qu'elle se relance même quand le JavaScript a échoué. --}}
                <form method="POST" action="{{ route('admin.visite.rejouer') }}" style="margin:0;">
                    @csrf
                    <button type="submit" style="width:100%; display:flex; align-items:center; gap:8px; padding:8px 14px; font-size:12.5px; color:var(--text); border:none; background:none; cursor:pointer; text-align:left; font-family:inherit; transition:background 0.15s;" onmouseover="this.style.background='#F1F5F9'" onmouseout="this.style.background='none'">
                        <i class="far fa-circle-question" style="width:14px; color:var(--text-2);"></i> Revoir la visite guidée
                    </button>
                </form>
                <div style="border-top:1px solid var(--border); margin:4px 0;"></div>
                <form method="POST" action="{{ route('deconnexion') }}" id="formDeconnexionDropdown" style="margin:0;">
                    @csrf
                    <button type="submit" style="width:100%; display:flex; align-items:center; gap:8px; padding:8px 14px; font-size:12.5px; color:var(--danger); border:none; background:none; cursor:pointer; text-align:left; font-family:inherit; transition:background 0.15s;" onmouseover="this.style.background='#FEF2F2'" onmouseout="this.style.background='none'">
                        <i class="fas fa-right-from-bracket" style="width:14px;"></i> Se déconnecter
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>

<!-- ────────────────── CONTENU PRINCIPAL ────────────────── -->
<div class="main-wrap">
    <main class="main-content">

        {{-- Les messages flash apparaissent en toast — un pop-up en surimpression,
             coin haut-droit —, non plus en bandeau : un bandeau en haut d'un écran
             chargé passe inaperçu. La collecte est ici, l'affichage en fin de page.
             Le message est posé par `textContent` côté JS, jamais interprété comme
             du HTML. --}}
        @php
            $__flashs = [];
            foreach ([
                'succes'        => 'Succès',
                'avertissement' => 'Attention',
                'erreur'        => 'Erreur',
                'info'          => 'Information',
            ] as $__cle => $__titre) {
                if (session()->has($__cle)) {
                    $__f = ['type' => $__cle, 'titre' => $__titre, 'message' => (string) session($__cle)];

                    // Une ou plusieurs actions facultatives — des boutons qui
                    // mènent où agir —, posées par le contrôleur sous
                    // « {cle}_action ». On accepte une action seule ou une liste.
                    $__brut = session($__cle . '_action');
                    if (is_array($__brut) && $__brut !== []) {
                        $__liste = array_key_exists('url', $__brut) ? [$__brut] : $__brut;
                        $__actions = [];
                        foreach ($__liste as $__a) {
                            if (is_array($__a) && !empty($__a['url']) && !empty($__a['label'])) {
                                $__actions[] = [
                                    'url'          => (string) $__a['url'],
                                    'label'        => (string) $__a['label'],
                                    'nom'          => (string) ($__a['nom'] ?? ''),
                                    'action_label' => (string) ($__a['action_label'] ?? ''),
                                    'method'       => strtolower((string) ($__a['method'] ?? 'get')),
                                ];
                            }
                        }
                        if ($__actions !== []) {
                            $__f['actions'] = $__actions;
                        }
                    }

                    $__flashs[] = $__f;
                }
            }
            if ($errors->any()) {
                $__flashs[] = ['type' => 'erreur', 'titre' => 'Erreur', 'message' => implode(' ', $errors->all())];
            }
        @endphp
        @if($__flashs)
        <script>window.__flashs = (window.__flashs || []).concat(@json($__flashs));</script>
        @endif

        {{-- Alerte de stickers. Placee ici plutot que dans chaque vue : le
             stock peut tomber a zero pendant n'importe quelle action, et
             l'utilisateur doit le voir ou qu'il se trouve. --}}
        @php
            $alerteStickers = \App\Modules\Admin\Services\AlerteStickersService::pour(
                Auth::user()?->entreprise
            );
        @endphp
        @if($alerteStickers)
            <details class="alerte-stickers {{ $alerteStickers['niveau'] === 'epuise' ? 'epuise' : '' }}" open>
                <summary>
                    <i class="fas {{ $alerteStickers['niveau'] === 'epuise' ? 'fa-circle-exclamation' : 'fa-triangle-exclamation' }}"></i>
                    @if($alerteStickers['niveau'] === 'epuise')
                        Stickers épuisés — plus aucune facture ne peut être normalisée
                    @else
                        Solde de stickers bas : {{ $alerteStickers['solde'] }} restant(s)
                        (seuil d'alerte : {{ $alerteStickers['seuil'] }})
                    @endif
                    <i class="fas fa-chevron-down chevron"></i>
                </summary>
                <div class="detail">
                    @if($alerteStickers['niveau'] === 'epuise')
                        La plateforme FNE refuse de certifier sans sticker. Vos ventes
                        continuent d'être enregistrées dans Selflow, mais elles
                        <strong>ne sont plus normalisées</strong> : elles ne portent ni numéro
                        FNE, ni code de vérification, et ne sont donc pas conformes
                        tant que le stock n'est pas reconstitué.
                    @else
                        Il vous reste de quoi certifier
                        <strong>{{ $alerteStickers['pieces_restantes'] }} pièce(s)</strong>,
                        soit une valeur de
                        <strong>{{ number_format($alerteStickers['valeur'], 0, ',', ' ') }} FCFA</strong>
                        (une vignette par facture, avoir ou bordereau, à
                        {{ number_format((float) config('selflow.sticker_prix_unitaire', 20), 0, ',', ' ') }} F l'unité).
                        <br>
                        Une fois à zéro, la plateforme refusera de certifier : vos ventes
                        resteront enregistrées mais <strong>ne seront plus normalisées</strong>,
                        sans numéro FNE ni code de vérification.
                    @endif
                    <br>
                    Rechargez votre solde depuis votre espace FNE
                    (<em>Gestion des stickers</em>). Le solde affiché ici est celui
                    renvoyé lors de la dernière certification : il se met à jour à la
                    suivante.
                </div>
            </details>
        @endif

        {{-- Une entreprise qui n'a pas fait sa configuration part d'une page
             blanche : ni catalogue, ni comptes de metier. Le dire ici, une
             fois pour toutes, evite qu'elle s'en apercoive a sa premiere
             facture. --}}
        @if(auth()->check() && auth()->user()->entreprise
            && !auth()->user()->entreprise->souscription_terminee_le
            && auth()->user()->role === 'admin'
            && !request()->routeIs('admin.souscription.*'))
            <div data-visite-banniere
                 style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;
                        background:#EFF6FF; border:1px solid #BFDBFE; border-radius:12px;
                        padding:16px 20px; margin-bottom:20px;">
                <span style="font-size:22px; line-height:1;">&#128640;</span>
                <div style="flex:1; min-width:260px;">
                    <div style="font-weight:700; font-size:14px; color:#1E3A8A; margin-bottom:3px;">
                        Configurez votre metier en cinq minutes
                    </div>
                    <div style="font-size:13px; color:#1D4ED8; line-height:1.55;">
                        Selflow remplira votre catalogue, votre plan comptable et vos journaux
                        a partir de votre activite. Il vous restera a saisir vos prix &mdash;
                        eux seuls varient selon la zone et la periode, nous ne pouvons pas les deviner.
                    </div>
                </div>
                <a href="{{ route('admin.souscription.index') }}"
                   style="white-space:nowrap; background:#1D4ED8; color:#fff; padding:9px 16px;
                          border-radius:8px; font-weight:600; font-size:13px; text-decoration:none;">
                    Commencer
                </a>
            </div>
        @endif

        @yield('contenu')
    </main>
</div>

<script>
    /**
     * Lit une quantité physique saisie par l'utilisateur.
     *
     * Les écrans lisaient `parseInt` : une réception de 12,5 kg de cacao
     * devenait 12 avant même de quitter le navigateur, et le serveur n'avait
     * plus rien à corriger. On lit désormais un décimal, arrondi au millième —
     * la précision de la colonne en base, et celle d'une balance commerciale.
     *
     * Une saisie vide, nulle ou négative vaut le plus petit pas : le sens d'un
     * mouvement de stock est porté par son type, jamais par le signe de sa
     * quantité.
     */
    const PAS_QUANTITE = 0.001;

    function quantiteSaisie(valeur) {
        const q = Math.round(parseFloat(valeur) * 1000) / 1000;

        return (isNaN(q) || q <= 0) ? PAS_QUANTITE : q;
    }

    /** Affichage court : « 12,5 » plutôt que « 12,500 », « 3 » plutôt que « 3,000 ». */
    function quantiteAffichee(valeur) {
        const q = parseFloat(valeur);

        return isNaN(q) ? '0' : String(Math.round(q * 1000) / 1000).replace('.', ',');
    }

    // Horloge en temps réel
    function majHorloge() {
        const el = document.getElementById('horloge');
        if (el) {
            const now = new Date();
            el.textContent = now.toLocaleTimeString('fr-FR');
        }
    }
    majHorloge();
    setInterval(majHorloge, 1000);

    // Toggle Sidebar
    document.getElementById('toggleSidebar')?.addEventListener('click', () => {
        if (window.innerWidth <= 768) {
            document.body.classList.toggle('sidebar-open');
        } else {
            document.body.classList.toggle('sidebar-collapsed');
        }
    });

    // Menu déroulant Profil utilisateur
    function toggleUserDropdown() {
        const menu = document.getElementById('userDropdownMenu');
        if (menu) {
            menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
        }
    }

    // Menu déroulant Période
    function togglePeriodeDropdown(e) {
        e.stopPropagation();
        const menu = document.getElementById('periodeDropdownMenu');
        if (menu) {
            menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
        }
    }

    // Fermeture automatique au clic extérieur
    document.addEventListener('click', (e) => {
        // Profil
        const btn = document.querySelector('.user-dropdown-btn');
        const menu = document.getElementById('userDropdownMenu');
        if (btn && menu && !btn.contains(e.target) && !menu.contains(e.target)) {
            menu.style.display = 'none';
        }

        // Période
        const pBtn = document.querySelector('.sidebar-periode button');
        const pMenu = document.getElementById('periodeDropdownMenu');
        if (pBtn && pMenu && !pBtn.contains(e.target) && !pMenu.contains(e.target)) {
            pMenu.style.display = 'none';
        }
    });

    // Fermeture auto des alertes
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(a => {
            a.style.transition = 'opacity .4s';
            a.style.opacity = '0';
            setTimeout(() => a.remove(), 400);
        });
    }, 5000);

    // Modals
    document.querySelectorAll('[data-modal-open]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById(btn.dataset.modalOpen)?.classList.add('open');
        });
    });
    document.querySelectorAll('[data-modal-close]').forEach(btn => {
        btn.addEventListener('click', () => {
            btn.closest('.modal-overlay')?.classList.remove('open');
        });
    });
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', e => {
            if (e.target === overlay) overlay.classList.remove('open');
        });
    });

    // Lecture seule globale si en mode aperçu
    @if($lectureSeule)
    document.addEventListener('DOMContentLoaded', () => {
        const elementsToDisable = document.querySelectorAll(
            'input:not(#toggleSidebar *), select:not(#toggleSidebar *), textarea:not(#toggleSidebar *), button:not(#toggleSidebar):not(.btn-quit-apercu):not(.btn-quit-apercu *):not(.logout-btn):not(.logout-btn *), a.btn:not(.nav-item)'
        );
        elementsToDisable.forEach(el => {
            if (el.closest('.sidebar')) return;
            el.disabled = true;
            el.style.pointerEvents = 'none';
            el.style.opacity = '0.65';
            if (el.tagName === 'A') {
                el.addEventListener('click', e => e.preventDefault());
            }
        });
    });
    @endif
</script>

{{-- ── MODAL 1 : ONBOARDING NOM DE L'ENTREPRISE (Google OAuth) ── --}}
@if(Auth::check() && Auth::user()->entreprise && Auth::user()->entreprise->nom === '[PENDING_ONBOARDING]')
<div id="onboarding-nom-modal" style="position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.5); backdrop-filter:blur(10px); z-index:99999; display:flex; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#ffffff; border-radius:16px; width:100%; max-width:480px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); padding:36px; border:1px solid rgba(226,232,240,0.8); text-align:center; animation: onboardingScaleUp 0.3s ease-out;">
        <div style="width:60px; height:60px; border-radius:14px; background:#EFF6FF; color:#1D4ED8; display:inline-flex; align-items:center; justify-content:center; font-size:26px; margin-bottom:20px; box-shadow:0 4px 10px rgba(29,78,216,0.15);">
            <i class="fas fa-building"></i>
        </div>
        <h2 style="font-size:22px; font-weight:800; color:#1E293B; margin-bottom:8px; font-family:'Inter', sans-serif;">Bienvenue sur Selflow 👋</h2>
        <p style="color:#64748B; font-size:14px; line-height:1.5; margin-bottom:26px; font-family:'Inter', sans-serif;">Pour commencer à explorer votre espace et visualiser l'application, veuillez renseigner le nom de votre entreprise.</p>
        
        <form action="{{ route('admin.onboarding.entreprise_nom') }}" method="POST" style="text-align:left;">
            @csrf
            <div style="margin-bottom:24px;">
                <label style="display:block; font-size:11px; font-weight:700; color:#64748B; text-transform:uppercase; margin-bottom:8px; letter-spacing:0.8px; font-family:'Inter', sans-serif;">Nom de votre entreprise</label>
                <input type="text" name="nom_entreprise" required placeholder="Ex: Commerce Général Ivoirien SARL" style="width:100%; padding:12px 16px; border:1.5px solid #E2E8F0; border-radius:10px; font-size:14px; font-family:inherit; color:#1E293B; outline:none; transition:all 0.2s;" onfocus="this.style.borderColor='#1D4ED8'; this.style.boxShadow='0 0 0 3px rgba(29,78,216,0.1)'" onblur="this.style.borderColor='#E2E8F0'; this.style.boxShadow='none'">
            </div>
            <button type="submit" style="width:100%; padding:13px; background:#002B5C; color:#ffffff; font-weight:600; border:none; border-radius:10px; font-size:14px; cursor:pointer; transition:all 0.2s; font-family:'Inter', sans-serif; display:flex; align-items:center; justify-content:center; gap:8px;" onmouseover="this.style.background='#001F42'" onmouseout="this.style.background='#002B5C'">
                Accéder à l'application <i class="fas fa-arrow-right"></i>
            </button>
        </form>
    </div>
</div>
<style>
@keyframes onboardingScaleUp {
    from { transform: scale(0.95); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}
</style>
@endif

{{-- ── MODAL 2 : INSCRIPTION INCOMPLÈTE (Bloquant Ventes/Achats/Devis) ── --}}
<div id="incomplete-registration-modal" style="position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.5); backdrop-filter:blur(6px); z-index:99998; display:none; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#ffffff; border-radius:16px; width:100%; max-width:480px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); padding:36px; border:1px solid rgba(226,232,240,0.8); text-align:center; animation: onboardingScaleUp 0.25s ease-out;">
        <div style="width:60px; height:60px; border-radius:14px; background:#FEF3C7; color:#D97706; display:inline-flex; align-items:center; justify-content:center; font-size:26px; margin-bottom:20px;">
            <i class="fas fa-triangle-exclamation"></i>
        </div>
        <h2 style="font-size:20px; font-weight:800; color:#1E293B; margin-bottom:10px; font-family:'Inter', sans-serif;">Il manque encore quelque chose</h2>

        {{-- L'écran disait « renseigner toutes les informations réglementaires »
             sans jamais dire **lesquelles** : l'utilisateur arrivait sur une
             page de paramètres de trois écrans de haut et cherchait. --}}
        @php
            $manquants = Auth::check() && Auth::user()->entreprise
                ? Auth::user()->entreprise->elementsInscriptionManquants()
                : [];
        @endphp

        <p style="color:#64748B; font-size:14px; line-height:1.6; margin-bottom:18px; font-family:'Inter', sans-serif;">
            Ces éléments partent avec chaque facture à la plateforme de la DGI. Tant qu'ils manquent,
            aucune vente ni aucun achat ne peut être enregistré.
        </p>

        @if($manquants)
            <ul style="text-align:left; margin:0 0 26px; padding:14px 16px 14px 34px; background:#FEF3C7; border:1px solid #FCD34D; border-radius:10px; color:#92400E; font-size:13.5px; line-height:1.8; font-family:'Inter', sans-serif;">
                @foreach($manquants as $manquant)
                    <li>{{ $manquant['libelle'] }}</li>
                @endforeach
            </ul>
        @endif

        <div style="display:flex; gap:12px; justify-content:center;">
            <button onclick="fermerModalIncomplet()" style="flex:1; padding:12px; background:#F1F5F9; color:#475569; font-weight:600; border:none; border-radius:10px; font-size:14px; cursor:pointer; transition:all 0.15s; font-family:'Inter', sans-serif;" onmouseover="this.style.background='#E2E8F0'" onmouseout="this.style.background='#F1F5F9'">
                Annuler
            </button>
            {{-- Le bouton menait toujours aux paramètres, même quand ce qui
                 manquait se réglait ailleurs : un point de vente se crée sur
                 son propre écran, un domaine d'activité au parcours. On envoie
                 là où se règle le premier manque. --}}
            @php
                $premier = $manquants[0]['ou'] ?? 'identite';
                $destination = match ($premier) {
                    'points_de_vente' => route('admin.pdv.index'),
                    'parcours'        => route('admin.souscription.index'),
                    default           => route('admin.entreprise.parametres') . '#' . $premier,
                };
            @endphp
            <a href="{{ $destination }}" style="flex:1; padding:12px; background:#002B5C; color:#ffffff; font-weight:600; border:none; border-radius:10px; font-size:14px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; transition:all 0.15s; font-family:'Inter', sans-serif;" onmouseover="this.style.background='#001F42'" onmouseout="this.style.background='#002B5C'">
                {{ ($manquants[0]['ou'] ?? '') === 'points_de_vente' ? 'Créer mon point de vente' : 'Compléter' }}
            </a>
        </div>
    </div>
</div>

<script>
function fermerModalIncomplet() {
    document.getElementById('incomplete-registration-modal').style.display = 'none';
}

// Retenir la position du scroll de la sidebar
document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        const savedScroll = localStorage.getItem('selflow_sidebar_scroll');
        if (savedScroll) {
            sidebar.scrollTop = parseInt(savedScroll);
        }
        sidebar.addEventListener('scroll', () => {
            localStorage.setItem('selflow_sidebar_scroll', sidebar.scrollTop);
        });
        window.addEventListener('beforeunload', () => {
            localStorage.setItem('selflow_sidebar_scroll', sidebar.scrollTop);
        });
    }
});

function ouvrirModalIncomplet() {
    document.getElementById('incomplete-registration-modal').style.display = 'flex';
}

// Ouvrir automatiquement si la session le demande
@if(session('ouvrir_modale_incomplet'))
document.addEventListener('DOMContentLoaded', () => {
    ouvrirModalIncomplet();
});
@endif

// Intercepter également au clic côté client si l'inscription est incomplète
@if(Auth::check() && Auth::user()->entreprise && !Auth::user()->entreprise->estInscriptionComplete())
document.addEventListener('DOMContentLoaded', () => {
    // Cibler tous les liens ou boutons pointant vers les ventes ou achats
    document.querySelectorAll('a, button').forEach(el => {
        const href = el.getAttribute('href');
        const onclick = el.getAttribute('onclick');
        
        if (href && (href.includes('/ventes') || href.includes('/achats') || href.includes('ventes.') || href.includes('achats.'))) {
            // Ignorer les boutons de la barre latérale si l'utilisateur veut juste naviguer
            // Mais bloquer la création/édition active (ex: /nouvelle, /nouveau)
            if (href.includes('/nouvelle') || href.includes('/nouveau') || href.includes('/creer') || href.includes('/enregistrer')) {
                el.addEventListener('click', (e) => {
                    e.preventDefault();
                    ouvrirModalIncomplet();
                });
            }
        }
    });
});
@endif
</script>

@yield('scripts')

@include('admin::partials.visite-guidee')

{{-- Zone des toasts (pop-up de notification), alimentée par window.__flashs.
     Succès et information disparaissent seuls ; avertissement et erreur
     restent jusqu'à ce qu'on les ferme — on ne rate pas un refus. --}}
<div class="toast-zone" id="toastZone" aria-live="polite" aria-atomic="true"></div>
<script>
window.__csrf = '{{ csrf_token() }}';
(function () {
    var zone = document.getElementById('toastZone');
    if (!zone) return;
    var flashs = window.__flashs || [];
    var icones = { succes:'fa-circle-check', avertissement:'fa-triangle-exclamation', erreur:'fa-circle-exclamation', info:'fa-circle-info' };
    var persistants = { avertissement:true, erreur:true };

    flashs.forEach(function (f, i) { setTimeout(function () { afficher(f); }, i * 160); });

    function afficher(f) {
        var el = document.createElement('div');
        el.className = 'toast t-' + (f.type || 'info');

        var ic = document.createElement('i');
        ic.className = 'fas ' + (icones[f.type] || 'fa-circle-info') + ' ic';

        var bd = document.createElement('div'); bd.className = 'bd';
        var ti = document.createElement('div'); ti.className = 'ti'; ti.textContent = f.titre || '';
        var ms = document.createElement('div'); ms.className = 'ms'; ms.textContent = f.message || '';
        bd.appendChild(ti); bd.appendChild(ms);

        // Actions ou liste sélective des points de vente
        if (Array.isArray(f.actions) && f.actions.length) {
            var estListePdv = f.actions.some(function (ac) { return ac.action_label || ac.nom; });

            if (estListePdv) {
                var listeDiv = document.createElement('div');
                listeDiv.className = 'toast-pdv-list';

                f.actions.forEach(function (ac) {
                    if (!ac || !ac.url || !ac.label) return;
                    var item = document.createElement('div');
                    item.className = 'toast-pdv-item';

                    var nomDiv = document.createElement('div');
                    nomDiv.className = 'toast-pdv-name';
                    nomDiv.innerHTML = '<i class="fas fa-store"></i> <span>' + (ac.nom || ac.label) + '</span>';

                    var btnActiver = document.createElement('button');
                    btnActiver.className = 'toast-pdv-btn';
                    btnActiver.innerHTML = '<i class="fas fa-bolt"></i> ' + (ac.action_label || 'Activer');
                    btnActiver.addEventListener('click', function (e) {
                        e.preventDefault();
                        btnActiver.disabled = true;
                        btnActiver.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Activation...';
                        var form = document.createElement('form');
                        form.method = 'POST';
                        form.action = ac.url;
                        var t = document.createElement('input');
                        t.type = 'hidden'; t.name = '_token';
                        t.value = (window.__csrf || '');
                        form.appendChild(t);
                        document.body.appendChild(form);
                        form.submit();
                    });

                    item.appendChild(nomDiv);
                    item.appendChild(btnActiver);
                    listeDiv.appendChild(item);
                });

                bd.appendChild(listeDiv);
            } else {
                var barre = document.createElement('div');
                barre.className = 'toast-actions';
                f.actions.forEach(function (ac) {
                    if (!ac || !ac.url || !ac.label) return;
                    var btn = document.createElement('a');
                    btn.className = 'toast-action' + (ac.method === 'post' ? '' : ' secondaire');
                    btn.href = ac.url;
                    btn.textContent = ac.label;
                    if (ac.method === 'post') {
                        btn.setAttribute('role', 'button');
                        btn.addEventListener('click', function (e) {
                            e.preventDefault();
                            var form = document.createElement('form');
                            form.method = 'POST';
                            form.action = ac.url;
                            var t = document.createElement('input');
                            t.type = 'hidden'; t.name = '_token';
                            t.value = (window.__csrf || '');
                            form.appendChild(t);
                            document.body.appendChild(form);
                            form.submit();
                        });
                    }
                    barre.appendChild(btn);
                });
                bd.appendChild(barre);
            }
        }

        var x = document.createElement('button');
        x.className = 'x'; x.setAttribute('aria-label', 'Fermer');
        x.innerHTML = '<i class="fas fa-xmark"></i>';

        el.appendChild(ic); el.appendChild(bd); el.appendChild(x);

        function fermer() { el.classList.add('sortie'); setTimeout(function () { el.remove(); }, 300); }
        x.addEventListener('click', fermer);
        zone.appendChild(el);

        if (!persistants[f.type]) { setTimeout(fermer, 6000); }
    }
})();
</script>

{{-- Polling automatique : quand le scraper est lancé après un échec de normalisation,
     le pop-up interroge le serveur et se met à jour automatiquement avec les points
     de vente récupérés du portail FNE. --}}
@if(session('rejet_en_cours_id'))
<script>
(function () {
    var rejetId = @json(session('rejet_en_cours_id'));
    var url = '/admin/fne/rejets/' + rejetId + '/statut-scraping';
    var tentatives = 0;
    var maxTentatives = 15;
    var intervalle = 8000;
    var zone = document.getElementById('toastZone');

    function interroger() {
        tentatives++;
        fetch(url, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.pret) {
                if (tentatives < maxTentatives) { setTimeout(interroger, intervalle); }
                return;
            }

            // Vider les toasts existants
            if (zone) { zone.innerHTML = ''; }

            if (data.resolu) {
                // Succès automatique : le point a été créé et la pièce normalisée
                afficherToast('succes', 'Succès', data.message);
                setTimeout(function () { location.reload(); }, 3000);
            } else if (data.choix && data.choix.length) {
                // Plusieurs points au choix sous forme de liste sélective
                afficherToastAvecBoutons('avertissement', 'Attention', data.message, data.choix);
            } else {
                afficherToast('info', 'Information', data.message);
            }
        })
        .catch(function () {
            if (tentatives < maxTentatives) { setTimeout(interroger, intervalle); }
        });
    }

    function afficherToast(type, titre, message) {
        if (!zone) return;
        var icones = { succes:'fa-circle-check', avertissement:'fa-triangle-exclamation', erreur:'fa-circle-exclamation', info:'fa-circle-info' };
        var el = document.createElement('div');
        el.className = 'toast t-' + type;
        el.innerHTML = '<i class="fas ' + (icones[type] || 'fa-circle-info') + ' ic"></i>'
            + '<div class="bd"><div class="ti">' + titre + '</div><div class="ms">' + message + '</div></div>'
            + '<button class="x" aria-label="Fermer"><i class="fas fa-xmark"></i></button>';
        el.querySelector('.x').addEventListener('click', function () {
            el.classList.add('sortie'); setTimeout(function () { el.remove(); }, 300);
        });
        zone.appendChild(el);
    }

    function afficherToastAvecBoutons(type, titre, message, boutons) {
        if (!zone) return;
        var icones = { succes:'fa-circle-check', avertissement:'fa-triangle-exclamation', erreur:'fa-circle-exclamation', info:'fa-circle-info' };
        var el = document.createElement('div');
        el.className = 'toast t-' + type;

        var ic = document.createElement('i');
        ic.className = 'fas ' + (icones[type] || 'fa-circle-info') + ' ic';

        var bd = document.createElement('div'); bd.className = 'bd';
        var ti = document.createElement('div'); ti.className = 'ti'; ti.textContent = titre;
        var ms = document.createElement('div'); ms.className = 'ms'; ms.textContent = message;
        bd.appendChild(ti); bd.appendChild(ms);

        var estListePdv = boutons.some(function (ac) { return ac.action_label || ac.nom; });

        if (estListePdv) {
            var listeDiv = document.createElement('div');
            listeDiv.className = 'toast-pdv-list';

            boutons.forEach(function (ac) {
                if (!ac || !ac.url || !ac.label) return;
                var item = document.createElement('div');
                item.className = 'toast-pdv-item';

                var nomDiv = document.createElement('div');
                nomDiv.className = 'toast-pdv-name';
                nomDiv.innerHTML = '<i class="fas fa-store"></i> <span>' + (ac.nom || ac.label) + '</span>';

                var btnActiver = document.createElement('button');
                btnActiver.className = 'toast-pdv-btn';
                btnActiver.innerHTML = '<i class="fas fa-bolt"></i> ' + (ac.action_label || 'Activer');
                btnActiver.addEventListener('click', function (e) {
                    e.preventDefault();
                    btnActiver.disabled = true;
                    btnActiver.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Activation...';
                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = ac.url;
                    var t = document.createElement('input');
                    t.type = 'hidden'; t.name = '_token';
                    t.value = (window.__csrf || '');
                    form.appendChild(t);
                    document.body.appendChild(form);
                    form.submit();
                });

                item.appendChild(nomDiv);
                item.appendChild(btnActiver);
                listeDiv.appendChild(item);
            });

            bd.appendChild(listeDiv);
        } else {
            var barre = document.createElement('div');
            barre.className = 'toast-actions';
            boutons.forEach(function (ac) {
                var btn = document.createElement('a');
                btn.className = 'toast-action' + (ac.method === 'post' ? '' : ' secondaire');
                btn.href = ac.url;
                btn.textContent = ac.label;
                if (ac.method === 'post') {
                    btn.setAttribute('role', 'button');
                    btn.addEventListener('click', function (e) {
                        e.preventDefault();
                        var form = document.createElement('form');
                        form.method = 'POST'; form.action = ac.url;
                        var t = document.createElement('input');
                        t.type = 'hidden'; t.name = '_token'; t.value = (window.__csrf || '');
                        form.appendChild(t);
                        document.body.appendChild(form); form.submit();
                    });
                }
                barre.appendChild(btn);
            });
            bd.appendChild(barre);
        }

        var x = document.createElement('button');
        x.className = 'x'; x.setAttribute('aria-label', 'Fermer');
        x.innerHTML = '<i class="fas fa-xmark"></i>';

        el.appendChild(ic); el.appendChild(bd); el.appendChild(x);
        x.addEventListener('click', function () {
            el.classList.add('sortie'); setTimeout(function () { el.remove(); }, 300);
        });
        zone.appendChild(el);
    }

    // Démarrer le polling après un court délai pour laisser le scraper partir
    setTimeout(interroger, 5000);
})();
</script>
@endif
</body>
</html>

