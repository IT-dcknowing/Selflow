@extends('admin::gabarits.application')
@section('titre', 'Prestataires')
@section('topbar_titre', 'Ventes · Prestataires')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-scissors"></i> Prestataires</h1>
        <p>Ceux qui réalisent les prestations. Sur chaque ligne de vente, « Réalisé par » dit qui a fait quoi, pour quel client.</p>
    </div>
</div>

@if(session('succes'))<div class="alert alert-success" style="margin-bottom:14px;"><i class="fas fa-circle-check"></i> {{ session('succes') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" style="margin-bottom:14px;">{{ $errors->first() }}</div>@endif

<div class="card" style="padding:16px 18px; margin-bottom:16px;">
    <form method="POST" action="{{ route('admin.intervenants.creer') }}" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)) auto; gap:10px; align-items:end;">
        @csrf
        <label class="form-label" style="margin:0;">Nom<input name="nom" class="form-control" required maxlength="120" placeholder="Ex. : Moussa"></label>
        <label class="form-label" style="margin:0;">Spécialité<input name="specialite" class="form-control" maxlength="120" placeholder="Ex. : coiffure hommes, manucure"></label>
        <label class="form-label" style="margin:0;">Téléphone<input name="telephone" class="form-control" maxlength="40"></label>
        <button class="btn btn-primary" style="height:40px;"><i class="fas fa-plus"></i> Ajouter</button>
    </form>
</div>

<form method="GET" style="display:flex; gap:10px; align-items:end; flex-wrap:wrap; margin-bottom:12px;">
    <label class="form-label" style="margin:0;">Du<input type="date" name="du" value="{{ $du->toDateString() }}" class="form-control"></label>
    <label class="form-label" style="margin:0;">Au<input type="date" name="au" value="{{ $au->toDateString() }}" class="form-control"></label>
    <button class="btn btn-outline"><i class="fas fa-filter"></i> Afficher</button>
</form>

<div class="card" style="padding:0; overflow-x:auto; margin-bottom:16px;">
    <table class="table" style="width:100%;">
        <thead><tr><th>Prestataire</th><th>Spécialité</th><th style="text-align:right;">Prestations</th><th style="text-align:right;">Clients</th><th style="text-align:right;">Chiffre (TTC)</th><th></th></tr></thead>
        <tbody>
        @forelse($intervenants as $i)
            @php $c = $chiffres->get($i->id); @endphp
            <tr style="{{ $i->actif ? '' : 'opacity:.55;' }}">
                <td><strong>{{ $i->nom }}</strong>@if($i->telephone)<div style="font-size:12px; color:var(--text-3);">{{ $i->telephone }}</div>@endif</td>
                <td>{{ $i->specialite ?: '' }}</td>
                <td style="text-align:right;">{{ (int) ($c->prestations ?? 0) }}</td>
                <td style="text-align:right;">{{ (int) ($c->clients ?? 0) }}</td>
                <td style="text-align:right; font-weight:700;">{{ number_format((float) ($c->chiffre ?? 0), 0, ',', ' ') }} F</td>
                <td style="text-align:right;">
                    <form method="POST" action="{{ route('admin.intervenants.basculer', $i) }}" style="margin:0;">@csrf
                        <button class="btn btn-outline btn-sm">{{ $i->actif ? 'Retirer des ventes' : 'Remettre' }}</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" style="text-align:center; color:var(--text-3); padding:20px;">Aucun prestataire. Ajoutez le premier ci-dessus.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="card" style="padding:0; overflow-x:auto;">
    <div style="padding:12px 16px; border-bottom:1px solid var(--border); font-weight:800;">Dernières prestations de la période</div>
    <table class="table" style="width:100%;">
        <thead><tr><th>Date</th><th>Facture</th><th>Client</th><th>Prestation</th><th>Réalisé par</th><th style="text-align:right;">Montant</th></tr></thead>
        <tbody>
        @forelse($dernieres as $d)
            <tr>
                <td>{{ \Illuminate\Support\Carbon::parse($d->date_vente)->format('d/m/Y') }}</td>
                <td>{{ $d->numero_facture }}</td>
                <td>{{ $d->client ?: 'Client de passage' }}</td>
                <td>{{ $d->prestation }}</td>
                <td><strong>{{ $d->intervenant }}</strong></td>
                <td style="text-align:right;">{{ number_format((float) $d->montant_ttc, 0, ',', ' ') }} F</td>
            </tr>
        @empty
            <tr><td colspan="6" style="text-align:center; color:var(--text-3); padding:20px;">Aucune prestation sur la période.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
