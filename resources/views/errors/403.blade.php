{{--
    Accès refusé (20/10/2026).

    La page brute de Laravel — « 403 | Accès refusé… » sur fond blanc — laissait
    l'utilisateur sans issue. Ici : ce qui se passe, en clair, et deux chemins
    pour repartir. Aucune ressource externe : la page doit s'afficher même
    quand le reste ne suit pas.
--}}
@php
    $message = trim((string) ($exception?->getMessage() ?? ''));
    if ($message === '' || str_starts_with($message, 'This action')) {
        $message = 'Vous ne disposez pas de l\'habilitation requise pour cette page.';
    }
    $utilisateur = auth()->user();
    $accueil = match (true) {
        !$utilisateur => route('connexion'),
        $utilisateur->estSuperAdmin() => route('superadmin.tableau_de_bord'),
        $utilisateur->estCaissier() => route('caissier.tableau_de_bord'),
        \App\Modules\Authentification\Regles\Habilitations::ouvre($utilisateur, 'admin.tableau_de_bord') => route('admin.tableau_de_bord'),
        // Sans tableau de bord, son profil : la seule page ouverte à tous.
        default => route('admin.mon_profil'),
    };
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accès refusé — Selflow</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
               background: #F4F6F9; font-family: Inter, system-ui, -apple-system, 'Segoe UI', sans-serif; color: #0f172a; }
        .boite { background: #fff; border-radius: 16px; box-shadow: 0 20px 50px rgba(0, 43, 92, .12); max-width: 480px; width: 100%;
                 padding: 36px 32px; text-align: center; border-top: 5px solid #002B5C; }
        .icone { width: 64px; height: 64px; border-radius: 50%; background: #fef3c7; color: #b45309; display: flex;
                 align-items: center; justify-content: center; margin: 0 auto 18px; font-size: 30px; font-weight: 800; }
        h1 { font-size: 20px; margin-bottom: 10px; color: #002B5C; }
        p { font-size: 14.5px; line-height: 1.55; color: #475569; }
        .aide { font-size: 13px; color: #64748b; margin-top: 10px; }
        .actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; margin-top: 24px; }
        a, button { font: inherit; font-size: 14px; font-weight: 700; padding: 10px 18px; border-radius: 10px; cursor: pointer; text-decoration: none; }
        .principal { background: #002B5C; color: #fff; border: none; }
        .secondaire { background: #fff; color: #002B5C; border: 1.5px solid #cbd5e1; }
    </style>
</head>
<body>
    <div class="boite" role="alert">
        <div class="icone" aria-hidden="true">!</div>
        <h1>Accès non autorisé</h1>
        <p>{{ $message }}</p>
        <p class="aide">Si vous pensez devoir y accéder, demandez à l'administrateur de votre entreprise de vous accorder ce droit.</p>
        <div class="actions">
            <button type="button" class="secondaire" onclick="history.length > 1 ? history.back() : location.assign('{{ $accueil }}')">Revenir en arrière</button>
            <a href="{{ $accueil }}" class="principal">Retour à l'accueil</a>
        </div>
    </div>
</body>
</html>
