@extends('admin::gabarits.application')
@section('titre', 'Emballages consignés')
@section('topbar_titre', 'Stock — Emballages consignés')

@section('contenu')
@use(App\Modules\Admin\Modeles\Consignation)

@php $auClient = $sens === Consignation::AU_CLIENT; @endphp

<div class="page-header">
    <div>
        <h1><i class="fas fa-box-open"></i> Emballages consignés</h1>
        <p>
            {{ $auClient
                ? 'Ce que vous avez prêté à vos clients. La consignation est une dette : elle vit au passif jusqu\'au retour de l\'emballage.'
                : 'Ce que vos fournisseurs vous ont prêté. La consignation versée est une créance, à l\'actif.' }}
        </p>
    </div>
</div>

<div style="display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap;">
    <a href="{{ route('admin.consignations.index', ['sens' => 'client', 'etat' => $etat]) }}"
       class="btn" style="{{ $auClient ? 'background:#0D1B3E; color:#fff;' : '' }}">
        <i class="fas fa-user"></i> Consigné aux clients
    </a>
    <a href="{{ route('admin.consignations.index', ['sens' => 'fournisseur', 'etat' => $etat]) }}"
       class="btn" style="{{ !$auClient ? 'background:#0D1B3E; color:#fff;' : '' }}">
        <i class="fas fa-truck"></i> Consigné par les fournisseurs
    </a>
</div>

{{-- Ce qui dort chez les tiers. Un dépôt ne savait pas combien de casiers sont
     dehors, ni depuis quand, ni chez qui. --}}
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:22px;">
    <div class="card" style="padding:18px;">
        <div style="font-size:12px; color:var(--text-3); text-transform:uppercase;">Emballages dehors</div>
        <div style="font-size:22px; font-weight:800; margin-top:6px;">@qte($dehors['quantite'])</div>
    </div>
    <div class="card" style="padding:18px;">
        <div style="font-size:12px; color:var(--text-3); text-transform:uppercase;">
            {{ $auClient ? 'Dette au passif (419400)' : 'Créance à l\'actif (409400)' }}
        </div>
        <div style="font-size:22px; font-weight:800; margin-top:6px; color:var(--primary);">
            {{ number_format($dehors['montant'], 0, ',', ' ') }} F
        </div>
    </div>
    <div class="card" style="padding:18px;">
        <div style="font-size:12px; color:var(--text-3); text-transform:uppercase;">Délai dépassé</div>
        <div style="font-size:22px; font-weight:800; margin-top:6px; color:{{ $dehors['en_retard'] > 0 ? 'var(--danger)' : 'var(--text-3)' }};">
            {{ $dehors['en_retard'] }}
        </div>
    </div>
</div>

<div class="card" style="padding:22px; margin-bottom:22px;">
    <h2 style="font-size:15px; font-weight:700; margin-bottom:16px;">Consigner un emballage</h2>

    <form method="POST" action="{{ route('admin.consignations.enregistrer') }}">
        @csrf
        <input type="hidden" name="sens" value="{{ $sens }}">

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; align-items:end;">
            <div class="form-group">
                <label class="form-label">{{ $auClient ? 'Client' : 'Fournisseur' }}</label>
                @if($auClient)
                    <select name="client_id" class="form-control" required>
                        <option value="">Choisir</option>
                        @foreach($clients as $c)
                            <option value="{{ $c->id }}">{{ $c->nom }}</option>
                        @endforeach
                    </select>
                @else
                    <select name="fournisseur_id" class="form-control" required>
                        <option value="">Choisir</option>
                        @foreach($fournisseurs as $f)
                            <option value="{{ $f->id }}">{{ $f->nom }}</option>
                        @endforeach
                    </select>
                @endif
            </div>

            <div class="form-group">
                <label class="form-label">Emballage</label>
                <select name="produit_id" class="form-control">
                    <option value="">hors catalogue</option>
                    @foreach($emballages as $e)
                        <option value="{{ $e->id }}" data-prix="{{ $e->prix_consignation }}">
                            {{ $e->nom }} ({{ number_format($e->prix_consignation, 0, ',', ' ') }} F)
                        </option>
                    @endforeach
                </select>
                <small style="color:var(--text-3); font-size:11px;">
                    Un article porte son prix de consignation : le ressaisir à chaque vente serait une source d'écart.
                </small>
            </div>

            <div class="form-group">
                <label class="form-label">Désignation, hors catalogue</label>
                <input type="text" name="designation" class="form-control" maxlength="200" placeholder="Palette bois">
            </div>

            <div class="form-group">
                <label class="form-label">Quantité</label>
                <input type="number" step="0.001" min="0.001" name="quantite" class="form-control" required>
            </div>

            <div class="form-group">
                <label class="form-label">Prix unitaire</label>
                <input type="number" step="0.01" min="0" name="prix_consigne" class="form-control"
                       placeholder="celui de la fiche">
            </div>

            <div class="form-group">
                <label class="form-label">Référence de pièce</label>
                <input type="text" name="reference" class="form-control" maxlength="100" placeholder="VTE-000042">
            </div>

            <div class="form-group">
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Consigner</button>
            </div>
        </div>
    </form>
</div>

<div style="display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap;">
    @foreach(['en_cours' => 'En cours', 'en_retard' => 'Délai dépassé', 'closes' => 'Closes', 'tout' => 'Tout'] as $cle => $libelle)
        <a href="{{ route('admin.consignations.index', ['sens' => $sens, 'etat' => $cle]) }}"
           class="btn" style="padding:6px 12px; font-size:12.5px; {{ $etat === $cle ? 'background:var(--bg3);' : '' }}">
            {{ $libelle }}
        </a>
    @endforeach
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ $auClient ? 'Client' : 'Fournisseur' }}</th>
                    <th>Emballage</th>
                    <th>Consigné</th>
                    <th>Rendu</th>
                    <th>Dehors</th>
                    <th>Reste dû</th>
                    <th>Échéance</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($consignations as $c)
                    <tr style="{{ $c->estEnRetard() ? 'background:#fef2f2;' : '' }}">
                        <td>{{ $auClient ? $c->client?->nom : $c->fournisseur?->nom }}</td>
                        <td>
                            <strong>{{ $c->designation }}</strong>
                            <div style="font-size:11px; color:var(--text-3);">
                                {{ number_format($c->prix_consigne, 0, ',', ' ') }} F l'unité
                                @if($c->reference_document) · {{ $c->reference_document }} @endif
                            </div>
                        </td>
                        <td>@qte($c->quantite)</td>
                        <td>@qte($c->quantite_rendue)</td>
                        <td style="font-weight:700;">@qte($c->quantiteDehors())</td>
                        <td>{{ number_format($c->resteDu(), 0, ',', ' ') }}</td>
                        <td>
                            @if($c->date_limite_retour)
                                <span style="{{ $c->estEnRetard() ? 'color:var(--danger); font-weight:700;' : '' }}">
                                    {{ $c->date_limite_retour->format('d/m/Y') }}
                                </span>
                            @else
                                <span style="color:var(--text-3);">sans terme</span>
                            @endif
                        </td>
                        <td>
                            @if($c->estClose())
                                <span style="color:var(--text-3); font-size:12px;">
                                    {{ $c->statut === Consignation::RENDUE ? 'Rendu' : 'Non rendu' }}
                                    le {{ $c->date_cloture?->format('d/m/Y') }}
                                </span>
                            @else
                                <form method="POST" action="{{ route('admin.consignations.rendre', $c) }}"
                                      style="display:flex; gap:6px; align-items:center;">
                                    @csrf
                                    <input type="number" step="0.001" min="0.001" max="{{ $c->quantiteDehors() }}"
                                           name="quantite" value="{{ $c->quantiteDehors() }}"
                                           class="form-control" style="width:80px; padding:4px 6px; font-size:12px;">
                                    <input type="number" step="0.01" min="0" max="{{ $c->prix_consigne }}"
                                           name="prix_de_reprise" placeholder="prix"
                                           class="form-control" style="width:80px; padding:4px 6px; font-size:12px;">
                                    <button type="submit" class="btn" style="padding:4px 10px; font-size:12px;">Reprendre</button>
                                </form>
                                <form method="POST" action="{{ route('admin.consignations.non_retour', $c) }}"
                                      style="margin-top:6px;"
                                      onsubmit="return confirm('Constater que cet emballage ne reviendra pas ?');">
                                    @csrf
                                    <button type="submit" class="btn" style="padding:4px 10px; font-size:12px; color:var(--danger);">
                                        Non rendu
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align:center; padding:40px; color:var(--text-3); font-style:italic;">
                            Rien de consigné. Un casier, une bouteille de gaz, une palette : tout ce qui se prête contre
                            une somme rendue au retour a sa place ici.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="padding:14px;">{{ $consignations->links() }}</div>
</div>

{{-- Le non-retour est une vente, soumise à la TVA et à la certification de la
     plateforme : elle passe par l'écran de vente ordinaire, dont la conformité
     est acquise et gelée. --}}
@if($auClient)
<div style="margin-top:20px; padding:14px 18px; background:var(--bg3); border-radius:10px; font-size:12.5px; color:var(--text-2);">
    <i class="fas fa-circle-info"></i>
    Constater un non-retour éteint la dette et constate le boni au compte <strong>707400</strong>.
    La part fiscale — TVA et certification — passe par une facture ordinaire, depuis l'écran des ventes.
</div>
@endif
@endsection
