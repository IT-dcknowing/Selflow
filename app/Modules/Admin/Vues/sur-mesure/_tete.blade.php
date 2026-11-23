{{-- Selflow sur mesure : ce que partagent l'accueil et la connexion d'un espace. --}}
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
@if($espace->icone)
    <link rel="icon" href="{{ $espace->media('icone') }}">
@else
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/selflow/favicon-32.png') }}">
@endif
<link rel="stylesheet" href="{{ asset('vendor/inter/inter.css') }}">
<link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
<style>
    :root {
        --principale: {{ $principale }};
        --principale-fonce: {{ $espace->ombre($principale, .22) }};
        --principale-pale: {{ $espace->teinte($principale, .9) }};
        --secondaire: {{ $secondaire }};
        --texte: #0f172a;
        --doux: #64748b;
        --bord: #e2e8f0;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Inter', system-ui, sans-serif; color: var(--texte); background: #fff; -webkit-font-smoothing: antialiased; }
    a { color: inherit; }
    .propulse { font-size: 12.5px; color: var(--doux); text-align: center; padding: 18px 16px; }
    .propulse strong { color: var(--texte); letter-spacing: .02em; }
    .bandeau-apercu { position: sticky; top: 0; z-index: 50; background: #fef3c7; color: #92400e; font-size: 13px; font-weight: 700; text-align: center; padding: 8px 16px; }
</style>
