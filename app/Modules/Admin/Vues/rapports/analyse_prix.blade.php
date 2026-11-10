@extends('admin::gabarits.application')

@section('titre', 'Analyse des prix')
@section('topbar_titre', 'Rapports — Analyse des prix')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-tags"></i> Analyse des prix par produit et vendeur</h1>
        <p>Comparez les prix unitaires réellement facturés, vendeur par vendeur, sur les points de vente autorisés.</p>
    </div>
    <a href="{{ route('admin.rapports.analyse_activite') }}" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour aux rapports
    </a>
</div>

<form method="GET" action="{{ route('admin.rapports.analyse_prix') }}" class="card" style="padding:16px;margin-bottom:18px;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:end;">
        <div>
            <label class="form-label" for="prix-debut">Du</label>
            <input class="form-control" type="date" id="prix-debut" name="debut" value="{{ request('debut') }}">
        </div>
        <div>
            <label class="form-label" for="prix-fin">Au</label>
            <input class="form-control" type="date" id="prix-fin" name="fin" value="{{ request('fin') }}">
        </div>
        @unless(auth()->user()->estAffecteAUnSite())
        <div>
            <label class="form-label" for="prix-pdv">Point de vente</label>
            <select class="form-control" id="prix-pdv" name="pdv_id">
                <option value="">Tous les points de vente</option>
                @foreach($pointsDeVente as $pdv)
                    <option value="{{ $pdv->id }}" @selected((string)request('pdv_id') === (string)$pdv->id)>{{ $pdv->nom }}</option>
                @endforeach
            </select>
        </div>
        @endunless
        <div>
            <label class="form-label" for="prix-vendeur">Vendeur</label>
            <select class="form-control" id="prix-vendeur" name="vendeur_id">
                <option value="">Tous les vendeurs</option>
                @foreach($vendeurs as $vendeur)
                    <option value="{{ $vendeur->id }}" @selected((string)request('vendeur_id') === (string)$vendeur->id)>{{ $vendeur->nom }} {{ $vendeur->prenom }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="prix-produit">Produit / référence</label>
            <input class="form-control" id="prix-produit" name="produit" value="{{ request('produit') }}" placeholder="Nom ou référence">
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-primary" type="submit"><i class="fas fa-filter"></i> Filtrer</button>
            <a class="btn btn-outline" href="{{ route('admin.rapports.analyse_prix') }}">Effacer</a>
        </div>
    </div>
</form>

<div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <strong>{{ $lignes->total() }} combinaison(s) produit / vendeur</strong>
        <span class="text-muted" style="font-size:12px;">Prix unitaires en FCFA — montants issus des factures validées</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Produit</th><th>Vendeur</th><th class="text-end">Quantité</th>
                    <th class="text-end">Prix minimum</th><th class="text-end">Prix moyen</th>
                    <th class="text-end">Prix maximum</th><th class="text-end">CA TTC</th><th class="text-end">Factures</th>
                </tr>
            </thead>
            <tbody>
                @forelse($lignes as $ligne)
                    <tr>
                        <td>
                            <strong>{{ $ligne->nom_produit ?: ($ligne->libelle_virtuel ?: 'Article supprimé') }}</strong>
                            @if($ligne->reference_produit)
                                <div class="text-muted" style="font-size:12px;">Réf. {{ $ligne->reference_produit }}</div>
                            @endif
                        </td>
                        <td>{{ trim(($ligne->vendeur_nom ?? '') . ' ' . ($ligne->vendeur_prenom ?? '')) ?: 'Utilisateur inconnu' }}</td>
                        <td class="text-end">{{ number_format((float)$ligne->quantite_vendue, 3, ',', ' ') }}</td>
                        <td class="text-end">{{ number_format((float)$ligne->prix_minimum, 2, ',', ' ') }}</td>
                        <td class="text-end"><strong>{{ number_format((float)$ligne->prix_moyen, 2, ',', ' ') }}</strong></td>
                        <td class="text-end">{{ number_format((float)$ligne->prix_maximum, 2, ',', ' ') }}</td>
                        <td class="text-end">{{ number_format((float)$ligne->chiffre_affaires, 0, ',', ' ') }}</td>
                        <td class="text-end">{{ $ligne->nombre_factures }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" style="text-align:center;padding:30px;">Aucune ligne de vente ne correspond aux filtres.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($lignes->hasPages())
        <div style="padding:16px 20px;">{{ $lignes->links() }}</div>
    @endif
</div>
@endsection
