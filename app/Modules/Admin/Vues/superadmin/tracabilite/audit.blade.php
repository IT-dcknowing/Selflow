@extends('admin::gabarits.application')
@section('titre', 'Traçabilité par entreprise')
@section('topbar_titre', 'SuperAdmin — Traçabilité par entreprise')

@section('contenu')
<div style="max-width:1200px; margin:0 auto;">
    <div style="display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap;">
        <a href="{{ route('superadmin.tracabilite.index') }}" class="btn btn-sm btn-outline"><i class="fas fa-chart-line"></i> Trafic & IP</a>
        <a href="{{ route('superadmin.tracabilite.audit') }}" class="btn btn-sm btn-primary"><i class="fas fa-list-check"></i> Traçabilité par entreprise</a>
    </div>

    <div class="card" style="padding:14px 18px; margin-bottom:14px;">
        <form method="GET" action="{{ route('superadmin.tracabilite.audit') }}" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px; align-items:end;">
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:11px;">Entreprise</label>
                <select name="entreprise" class="form-control">
                    <option value="">— Toutes —</option>
                    @foreach($entreprises as $e)
                        <option value="{{ $e->id }}" {{ (string) ($filtres['entreprise'] ?? '') === (string) $e->id ? 'selected' : '' }}>{{ $e->nom }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:11px;">Action contient</label>
                <input type="text" name="action" class="form-control" value="{{ $filtres['action'] ?? '' }}" placeholder="creation, suppression…">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:11px;">Du</label>
                <input type="date" name="du" class="form-control" value="{{ $filtres['du'] ?? '' }}">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:11px;">Au</label>
                <input type="date" name="au" class="form-control" value="{{ $filtres['au'] ?? '' }}">
            </div>
            <div style="display:flex; gap:6px;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filtrer</button>
                <a href="{{ route('superadmin.tracabilite.audit') }}" class="btn btn-outline btn-sm">Réinitialiser</a>
            </div>
        </form>
    </div>

    <div class="card" style="padding:0; overflow-x:auto;">
        @if($journal->isEmpty())
            <p style="padding:30px; text-align:center; color:var(--text-3);">Aucune action enregistrée pour ces critères.</p>
        @else
        <table class="table" style="width:100%; font-size:12.5px;">
            <thead><tr><th>Quand</th><th>Action</th><th>Entreprise</th><th>Qui</th><th>D'où (IP)</th><th>Pièce</th></tr></thead>
            <tbody>
            @foreach($journal as $ligne)
                <tr>
                    <td style="white-space:nowrap;">{{ $ligne->created_at?->format('d/m/Y H:i:s') }}</td>
                    <td><strong>{{ $ligne->libelleAction() }}</strong></td>
                    <td>{{ $ligne->entreprise?->nom ?? '—' }}</td>
                    <td>
                        @if($ligne->utilisateur)
                            {{ trim(($ligne->utilisateur->prenom ?? '') . ' ' . ($ligne->utilisateur->nom ?? '')) ?: $ligne->utilisateur->email }}
                            <div style="font-size:11px; color:var(--text-3);">{{ $ligne->utilisateur->email }} · {{ $ligne->utilisateur->role }}</div>
                        @else
                            <span style="color:var(--text-3);">système / anonyme</span>
                        @endif
                    </td>
                    <td style="font-family:ui-monospace,monospace;">{{ $ligne->adresse_ip ?? '—' }}</td>
                    <td style="color:var(--text-3);">{{ $ligne->entite ? $ligne->entite . ' #' . $ligne->entite_id : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div style="padding:12px 16px;">{{ $journal->links() }}</div>
        @endif
    </div>
</div>
@endsection
