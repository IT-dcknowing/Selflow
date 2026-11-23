<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 22px 24px 40px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 7.6px; color: #0f172a; }
    h1 { font-size: 15px; margin: 0; color: #002B5C; }
    .sous { color: #64748b; font-size: 8.5px; margin: 3px 0 10px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #002B5C; color: #fff; font-weight: bold; padding: 5px 4px; text-align: left; font-size: 7.2px; }
    td { padding: 4px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    tr:nth-child(even) td { background: #f8fafc; }
    .num { text-align: right; }
    .ok { color: #047857; font-weight: bold; }
    .attente { color: #b45309; font-weight: bold; }
</style>
</head>
<body>
    <h1>Entreprises Selflow</h1>
    <div class="sous">Export du {{ now()->format('d/m/Y à H:i') }} · {{ count($lignes) }} entreprise(s){{ ($filtre ?? 'tous') !== 'tous' ? ' · filtre : ' . $filtre : '' }}</div>
    <table>
        <thead><tr>@foreach($titres as $t)<th>{{ $t }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse($lignes as $l)
            <tr>
                <td class="num">{{ $l['n'] }}</td>
                <td><strong>{{ $l['nom'] }}</strong></td>
                <td>{{ $l['ncc'] }}</td>
                <td>{{ $l['rccm'] }}</td>
                <td>{{ $l['regime'] }}</td>
                <td>{{ $l['gerant'] }}</td>
                <td>{{ $l['email'] }}</td>
                <td>{{ $l['telephone'] }}</td>
                <td class="{{ $l['statut'] === 'Validée' ? 'ok' : 'attente' }}">{{ $l['statut'] }}</td>
                <td>{{ $l['offre'] }}</td>
                <td>{{ $l['echeance'] }}</td>
                <td class="num">{{ $l['sites'] }}</td>
                <td class="num">{{ $l['utilisateurs'] }}</td>
                <td class="num">{{ $l['articles'] }}</td>
                <td>{{ $l['fne'] }}</td>
                <td>{{ $l['creee'] }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ count($titres) }}">Aucune entreprise.</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
