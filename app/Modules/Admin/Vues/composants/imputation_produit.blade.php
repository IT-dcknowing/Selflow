{{--
    Les comptes d'un article (chantiers 3.3 et 6.4 du plan).

    Deux menus obligatoires — compte de vente, compte d'achat — figuraient sur
    chaque fiche, comptabilité ouverte ou non. Un catalogue de trois cents
    articles demandait six cents choix, et la plupart retombaient sur 701000 /
    601000 faute de mieux.

    Comptabilité fermée : rien. Ouverte : l'article hérite de la configuration
    globale, et le dit ; une case en fait une exception, qui prime parce
    qu'elle est portée par l'article lui-même.

    Paramètres : `cle` (identifiant unique dans la page), `produit` (ou null),
    `comptes` (le plan comptable), `comptabiliteOuverte`.
--}}
@if($comptabiliteOuverte)
@php
    $perso   = (bool) ($produit?->comptes_personnalises);
    $herite  = $produit ? \App\Modules\Admin\Services\ImputationService::heritage($produit) : null;
    $ventes  = $comptes->filter(fn ($c) => str_starts_with((string) $c->numero, '7'));
    $achats  = $comptes->filter(fn ($c) => str_starts_with((string) $c->numero, '6'));
@endphp
<div class="form-group" style="grid-column:1/-1; padding:10px 12px; background:var(--bg3); border:1px solid var(--border); border-radius:8px;">
    <div style="font-size:12px; color:var(--text-2); line-height:1.55;">
        <i class="fas fa-diagram-project" style="color:var(--primary);"></i>
        <strong>Hérite de la configuration globale</strong>
        @if($herite)
            — vente <code>{{ $herite['compte_vente'] }}</code>, achat <code>{{ $herite['compte_achat'] }}</code>
        @else
            — selon le type, la catégorie ou la configuration générale
        @endif
        @if(Route::has('admin.comptabilite.configuration') && Auth::user()->aHabilitation('comptabilite_plan_comptable'))
            · <a href="{{ route('admin.comptabilite.configuration') }}">la régler</a>
        @endif
    </div>
    <label style="display:flex; align-items:center; gap:8px; margin-top:8px; font-size:12px; font-weight:600; cursor:pointer;">
        <input type="checkbox" name="comptes_personnalises" value="1" {{ $perso ? 'checked' : '' }}
               onchange="(function (c) {
                   var b = document.getElementById('imputation_propre_{{ $cle }}');
                   b.style.display = c.checked ? 'grid' : 'none';
                   b.querySelectorAll('select').forEach(function (s) { s.disabled = !c.checked; });
               })(this)">
        Comptes propres à cet article — ils priment sur la configuration globale
    </label>
    {{-- Désactivés tant que la case est décochée : un champ désactivé n'est
         pas envoyé, et le serveur n'a rien à ignorer. --}}
    <div id="imputation_propre_{{ $cle }}" style="display:{{ $perso ? 'grid' : 'none' }}; grid-template-columns:1fr 1fr; gap:10px; margin-top:8px;">
        <div>
            <label class="form-label">Compte de vente</label>
            <select name="compte_vente" class="form-control" {{ $perso ? '' : 'disabled' }}>
                @foreach($ventes as $compte)
                    <option value="{{ $compte->numero }}" {{ ($produit?->compte_vente ?? $herite['compte_vente'] ?? '701000') == $compte->numero ? 'selected' : '' }}>
                        {{ $compte->numero }} — {{ $compte->libelle }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Compte d'achat</label>
            <select name="compte_achat" class="form-control" {{ $perso ? '' : 'disabled' }}>
                @foreach($achats as $compte)
                    <option value="{{ $compte->numero }}" {{ ($produit?->compte_achat ?? $herite['compte_achat'] ?? '601000') == $compte->numero ? 'selected' : '' }}>
                        {{ $compte->numero }} — {{ $compte->libelle }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>
</div>
@endif
