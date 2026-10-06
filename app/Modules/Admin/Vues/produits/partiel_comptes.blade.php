{{-- Les comptes d'un article, comptabilité ouverte — chantier 6.4.

     Plus de compte à choisir sur chaque fiche : l'article hérite de la
     configuration globale, et la fiche le dit. Une case ouvre deux champs ;
     cochée, l'exception est portée par l'article et prime sur tout le reste.
     Décochée, l'article revient à l'héritage — le serveur repose le compte
     hérité au lieu de garder en silence l'ancien. --}}
@php
    $exception = $produit
        && (\App\Modules\Admin\Services\ImputationService::estUneException($produit, 'compte_vente')
            || \App\Modules\Admin\Services\ImputationService::estUneException($produit, 'compte_achat'));
    $heriteVente = $produit ? \App\Modules\Admin\Services\ImputationService::compteHerite($produit, 'compte_vente') : null;
    $heriteAchat = $produit ? \App\Modules\Admin\Services\ImputationService::compteHerite($produit, 'compte_achat') : null;
@endphp
<div class="form-group" style="grid-column:1/-1;">
    <div style="padding:10px 12px; background:var(--bg3); border-radius:8px; font-size:12.5px; color:var(--text-2); line-height:1.6;">
        <i class="fas fa-sitemap" style="color:var(--primary);"></i>
        @if($produit)
            Hérite de la configuration globale :
            vente <strong style="font-family:monospace;">{{ $heriteVente }}</strong>,
            achat <strong style="font-family:monospace;">{{ $heriteAchat }}</strong>.
        @else
            Hérite de la configuration globale — selon son type et sa catégorie.
        @endif
        <a href="{{ route('admin.comptabilite.configuration') }}" style="margin-left:6px;">Configuration des comptes</a>
    </div>
    <label style="display:flex; align-items:center; gap:8px; margin-top:8px; font-size:12.5px; font-weight:600; cursor:pointer;">
        <input type="checkbox" name="comptes_personnalises" value="1" {{ $exception ? 'checked' : '' }}
               onchange="document.getElementById('comptes-propres-{{ $cle }}').style.display = this.checked ? 'grid' : 'none'">
        Personnaliser les comptes de cet article (prime sur la configuration globale)
    </label>
    <div id="comptes-propres-{{ $cle }}" style="display:{{ $exception ? 'grid' : 'none' }}; grid-template-columns:1fr 1fr; gap:10px; margin-top:8px;">
        <div>
            <label class="form-label">Compte de vente</label>
            <select name="compte_vente" class="form-control">
                @foreach($comptes->filter(fn ($c) => str_starts_with((string) $c->numero, '7')) as $compte)
                    <option value="{{ $compte->numero }}" {{ ($produit?->compte_vente ?? $heriteVente) == $compte->numero ? 'selected' : '' }}>{{ $compte->numero }} - {{ $compte->libelle }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Compte d'achat</label>
            <select name="compte_achat" class="form-control">
                @foreach($comptes->filter(fn ($c) => str_starts_with((string) $c->numero, '6')) as $compte)
                    <option value="{{ $compte->numero }}" {{ ($produit?->compte_achat ?? $heriteAchat) == $compte->numero ? 'selected' : '' }}>{{ $compte->numero }} - {{ $compte->libelle }}</option>
                @endforeach
            </select>
        </div>
    </div>
</div>
