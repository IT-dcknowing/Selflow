@extends('admin::gabarits.application')
@section('titre', 'Prestataires externes')
@section('topbar_titre', 'Tiers · Prestataires externes')

@section('contenu')
<div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
    <h1 style="font-size:20px; margin:0;"><i class="fas fa-hospital" style="color:var(--primary);"></i> Prestataires externes</h1>
    <form method="GET" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
        <label class="form-label" style="margin:0;">Du<input type="date" name="du" value="{{ $du }}" class="form-control"></label>
        <label class="form-label" style="margin:0;">Au<input type="date" name="au" value="{{ $au }}" class="form-control"></label>
        <button class="btn btn-outline btn-sm"><i class="fas fa-filter"></i> Afficher</button>
        <a href="{{ route('admin.clients.index', ['type_tiers' => 'prestataires']) }}" class="btn btn-outline btn-sm"><i class="fas fa-users"></i> Fiches</a>
    </form>
</div>

<div class="card" style="padding:0; overflow-x:auto;">
    <table class="table" style="width:100%;">
        <thead><tr><th>Prestataire</th><th style="text-align:right;">Factures</th><th style="text-align:right;">Patients envoyés</th><th style="text-align:right;">Chiffre d'affaires</th><th style="text-align:right;">Reste à encaisser</th><th></th></tr></thead>
        <tbody>
        @forelse($lignes as $l)
            <tr>
                <td><strong>{{ $l->nom }}</strong>@if($l->telephone)<div style="font-size:12px; color:var(--text-3);">{{ $l->telephone }}</div>@endif</td>
                <td style="text-align:right;">{{ $l->factures }}</td>
                <td style="text-align:right;">{{ (int) ($patients[$l->id] ?? 0) }}</td>
                <td style="text-align:right; font-weight:700;">{{ number_format((float) $l->chiffre, 0, ',', ' ') }} F</td>
                <td style="text-align:right; font-weight:700; color:{{ $l->du_reste > 0 ? '#b91c1c' : '#047857' }};">{{ number_format((float) $l->du_reste, 0, ',', ' ') }} F</td>
                <td style="text-align:right;"><a class="btn btn-outline btn-sm" href="{{ route('admin.ventes.factures', ['apporteur_filtre' => $l->id]) }}">Ses factures</a></td>
            </tr>
        @empty
            <tr><td colspan="6" style="text-align:center; color:var(--text-3); padding:20px;">Aucune facture de prestataire sur la période.</td></tr>
        @endforelse
        </tbody>
        @if($lignes->isNotEmpty())
        <tfoot><tr style="font-weight:800;"><td>Total</td><td style="text-align:right;">{{ $lignes->sum('factures') }}</td><td style="text-align:right;">{{ $patients->sum() }}</td>
            <td style="text-align:right;">{{ number_format((float) $lignes->sum('chiffre'), 0, ',', ' ') }} F</td><td style="text-align:right;">{{ number_format((float) $lignes->sum('du_reste'), 0, ',', ' ') }} F</td><td></td></tr></tfoot>
        @endif
    </table>
</div>
@endsection
