@extends('admin::gabarits.application')
@section('titre', 'Configuration des comptes')
@section('topbar_titre', 'Comptabilité — Configuration des comptes')

@use('App\Modules\Admin\Services\ImputationService', 'Imputation')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-sitemap"></i> Configuration des comptes</h1>
        <p>Les comptes de vente, d'achat et de variation de stock se règlent ici, une fois, et non sur chaque fiche produit.</p>
    </div>
</div>

{{-- La page en un tableau par niveau (26/10/2026) : vente, achat et variation
     de stock ensemble. Chaque compte en deux cases : le proposé, et le
     personnalisé qui passe devant. --}}
<div style="margin-bottom:18px; padding:14px 18px; background:#eff6ff; border:1px solid #93c5fd; border-radius:10px; color:#1e3a8a; font-size:12.5px; line-height:1.6;">
    <strong><i class="fas fa-circle-info"></i> Proposé, personnalisé : le personnalisé l'emporte.</strong>
    <strong>Proposé</strong> : le compte que Selflow donne de lui-même (le défaut, la famille du référentiel, le compte
    habituel du type, ou ce dont l'article hérite). <strong>Personnalisé</strong> : le compte que vous imposez ; vide, le
    proposé s'applique.<br>
    Un article prend d'abord son <strong>propre</strong> compte personnalisé, sinon celui de son <strong>type</strong>, sinon
    celui de sa <strong>catégorie</strong>, sinon la <strong>configuration générale</strong>, sinon le compte de sa famille
    ({{ $defauts['compte_vente'] }} à la vente et {{ $defauts['compte_achat'] }} à l'achat à défaut).
    Les variations de stock ne se règlent que là où il y a du stock. Les écritures déjà passées ne sont pas réécrites.
</div>

<datalist id="comptes-7">
    @foreach($comptes->filter(fn ($c) => str_starts_with((string) $c->numero, '7')) as $c)
        <option value="{{ $c->numero }}">{{ $c->libelle }}</option>
    @endforeach
</datalist>
<datalist id="comptes-6">
    @foreach($comptes->filter(fn ($c) => str_starts_with((string) $c->numero, '6')) as $c)
        <option value="{{ $c->numero }}">{{ $c->libelle }}</option>
    @endforeach
</datalist>
<datalist id="comptes-variation">
    @foreach($comptes->filter(fn ($c) => str_starts_with((string) $c->numero, '603') || str_starts_with((string) $c->numero, '73')) as $c)
        <option value="{{ $c->numero }}">{{ $c->libelle }}</option>
    @endforeach
</datalist>

<style>
    .cfg-onglets { display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap; }
    .cfg-onglets button { padding:7px 14px; border-radius:20px; border:1px solid var(--border); background:var(--surface); cursor:pointer; font-size:12.5px; font-weight:600; color:var(--text-2); }
    .cfg-onglets button.actif { background:var(--primary); color:#fff; border-color:var(--primary); }
    .cfg-panneau { display:none; }
    .cfg-panneau.actif { display:block; }
    .cfg-defile { overflow-x:auto; }
    .cfg-table { width:100%; border-collapse:collapse; font-size:12.5px; }
    .cfg-table th, .cfg-table td { padding:7px 8px; border-bottom:1px solid var(--border); text-align:left; vertical-align:middle; }
    .cfg-table thead tr:first-child th { text-align:center; background:var(--bg3); border-bottom:none; font-size:12px; }
    .cfg-table thead tr:first-child th:first-child { text-align:left; }
    .cfg-table thead tr:nth-child(2) th { font-size:11px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:.03em; background:var(--bg3); }
    .cfg-table .groupe { border-left:2px solid var(--border); }
    .cfg-table input { width:118px; font-family:ui-monospace, Menlo, Consolas, monospace; font-size:12.5px; padding:5px 8px; }
    .cfg-propose { color:var(--text-3); font-family:ui-monospace, Menlo, Consolas, monospace; white-space:nowrap; }
    .cfg-sans { color:var(--text-3); font-size:11.5px; font-style:italic; }
</style>

<form method="POST" action="{{ route('admin.comptabilite.configuration.enregistrer') }}" id="form-configuration-comptes">
    @csrf
    @method('PUT')

    <div class="card" style="padding:22px 24px; margin-bottom:18px;" data-section="comptes">
        <div class="cfg-onglets" role="tablist">
            <button type="button" class="actif" data-onglet="general">Configuration générale</button>
            <button type="button" data-onglet="categorie">Par catégorie</button>
            <button type="button" data-onglet="type">Par type</button>
            <button type="button" data-onglet="article">Par article</button>
        </div>

        {{-- En-tête commun : trois groupes de deux colonnes. --}}
        @php
            $entete = function (string $premiere, bool $avecStock = false) use ($variationEnPlace) {
                $variation = $variationEnPlace
                    ? '<th colspan="2" class="groupe">Variation de stock</th>'
                    : '';
                $sousVariation = $variationEnPlace ? '<th class="groupe">Proposé</th><th>Personnalisé</th>' : '';
                return '<thead><tr><th rowspan="2">' . e($premiere) . '</th>'
                    . '<th colspan="2" class="groupe">Vente</th><th colspan="2" class="groupe">Achat</th>' . $variation
                    . ($avecStock ? '<th rowspan="2" class="groupe">Compte de stock</th>' : '') . '</tr>'
                    . '<tr><th class="groupe">Proposé</th><th>Personnalisé</th><th class="groupe">Proposé</th><th>Personnalisé</th>' . $sousVariation . '</tr></thead>';
            };
            $valeur = fn (string $cle, string $champ) => $lignes[$cle]->$champ ?? '';
        @endphp

        {{-- ── Configuration générale : vente et achat de tous les articles. ── --}}
        <div class="cfg-panneau actif" data-panneau="general">
            <div class="cfg-defile">
            <table class="cfg-table">
                {!! $entete('Portée') !!}
                <tbody>
                    <tr>
                        <td><strong>Tous les articles</strong></td>
                        <td class="groupe cfg-propose">{{ $defauts['compte_vente'] }}</td>
                        <td><input type="text" class="form-control" list="comptes-7" name="config[general:][compte_vente]"
                                   value="{{ $valeur('general:', 'compte_vente') }}" placeholder="{{ $defauts['compte_vente'] }}"></td>
                        <td class="groupe cfg-propose">{{ $defauts['compte_achat'] }}</td>
                        <td><input type="text" class="form-control" list="comptes-6" name="config[general:][compte_achat]"
                                   value="{{ $valeur('general:', 'compte_achat') }}" placeholder="{{ $defauts['compte_achat'] }}"></td>
                        @if($variationEnPlace)
                        <td class="groupe" colspan="2"><span class="cfg-sans">Se règle par catégorie, par type ou par article : elle dépend de la nature du stock.</span></td>
                        @endif
                    </tr>
                </tbody>
            </table>
            </div>
        </div>

        {{-- ── Par catégorie : le proposé est le compte de la famille. ── --}}
        <div class="cfg-panneau" data-panneau="categorie">
            @if($categories->isEmpty())
                <p style="color:var(--text-3); font-size:13px;">Aucune catégorie n'est encore créée.</p>
            @else
            <p style="font-size:12px; color:var(--text-3); margin-bottom:8px;">
                Le proposé est le compte de la famille, posé par le référentiel. Variations de stock : seulement pour les catégories qui ont des articles stockés.
            </p>
            <div class="cfg-defile">
            <table class="cfg-table">
                {!! $entete('Catégorie', true) !!}
                <tbody>
                @foreach($categories as $cat)
                    @php $cle = 'categorie:' . $cat->id; $avecStock = in_array((int) $cat->id, $categoriesAvecStock, true); @endphp
                    <tr>
                        <td>{{ $cat->nom }}</td>
                        <td class="groupe cfg-propose">{{ $cat->compte_vente ?: '—' }}</td>
                        <td><input type="text" class="form-control" list="comptes-7" name="config[{{ $cle }}][compte_vente]"
                                   value="{{ $valeur($cle, 'compte_vente') }}" placeholder="{{ $cat->compte_vente }}"></td>
                        <td class="groupe cfg-propose">{{ $cat->compte_achat ?: '—' }}</td>
                        <td><input type="text" class="form-control" list="comptes-6" name="config[{{ $cle }}][compte_achat]"
                                   value="{{ $valeur($cle, 'compte_achat') }}" placeholder="{{ $cat->compte_achat }}"></td>
                        @if($variationEnPlace)
                            @if($avecStock)
                            <td class="groupe cfg-propose">{{ $cat->compte_variation ?: '—' }}</td>
                            <td><input type="text" class="form-control" list="comptes-variation" name="config[{{ $cle }}][compte_variation]"
                                       value="{{ $valeur($cle, 'compte_variation') }}" placeholder="{{ $cat->compte_variation }}"></td>
                            @else
                            <td class="groupe" colspan="2"><span class="cfg-sans">Pas de stock</span></td>
                            @endif
                        @endif
                        <td class="groupe">
                            @if($avecStock)
                            <input type="text" class="form-control" name="stock[{{ $cat->id }}][compte_stock]" value="{{ $cat->compte_stock }}">
                            @else
                            <span class="cfg-sans">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            @endif
        </div>

        {{-- ── Par type : le proposé est le compte habituel du type (référentiel). ── --}}
        <div class="cfg-panneau" data-panneau="type">
            <p style="font-size:12px; color:var(--text-3); margin-bottom:8px;">
                Le proposé est le compte habituel du type, tiré du référentiel. Il ne s'impose que s'il est repris en personnalisé.
            </p>
            <div class="cfg-defile">
            <table class="cfg-table">
                {!! $entete("Type d'article") !!}
                <tbody>
                @foreach($types as $code => $libelle)
                    @php $cle = 'type:' . $code; $propose = $proposesParType[$code] ?? []; $stockable = in_array($code, $typesStockables, true); @endphp
                    <tr>
                        <td>{{ $libelle }}</td>
                        <td class="groupe cfg-propose">{{ $propose['compte_vente'] ?? '—' }}</td>
                        <td><input type="text" class="form-control" list="comptes-7" name="config[{{ $cle }}][compte_vente]"
                                   value="{{ $valeur($cle, 'compte_vente') }}" placeholder="{{ $propose['compte_vente'] ?? '' }}"></td>
                        <td class="groupe cfg-propose">{{ $propose['compte_achat'] ?? '—' }}</td>
                        <td><input type="text" class="form-control" list="comptes-6" name="config[{{ $cle }}][compte_achat]"
                                   value="{{ $valeur($cle, 'compte_achat') }}" placeholder="{{ $propose['compte_achat'] ?? '' }}"></td>
                        @if($variationEnPlace)
                            @if($stockable)
                            <td class="groupe cfg-propose">{{ $propose['compte_variation'] ?? '—' }}</td>
                            <td><input type="text" class="form-control" list="comptes-variation" name="config[{{ $cle }}][compte_variation]"
                                       value="{{ $valeur($cle, 'compte_variation') }}" placeholder="{{ $propose['compte_variation'] ?? '' }}"></td>
                            @else
                            <td class="groupe" colspan="2"><span class="cfg-sans">Ne se stocke pas</span></td>
                            @endif
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>

        {{-- ── Par article : le proposé est ce dont l'article hérite. ── --}}
        <div class="cfg-panneau" data-panneau="article">
            @if($articles->isEmpty())
                <p style="color:var(--text-3); font-size:13px;">Aucun article n'est encore créé.</p>
            @else
            <input type="search" class="form-control cfg-filtre" placeholder="Rechercher un article…" style="max-width:320px; margin-bottom:10px;">
            <p style="font-size:12px; color:var(--text-3); margin-bottom:8px;">
                Le proposé est ce dont l'article hérite. Un compte personnalisé ici passe devant tout le reste ; vide, l'article reprend le proposé.
            </p>
            <div class="cfg-defile" style="max-height:480px; overflow:auto;">
            <table class="cfg-table">
                {!! $entete('Article') !!}
                <tbody>
                @foreach($articles as $article)
                    @php
                        $vente = Imputation::estUneException($article, 'compte_vente') ? $article->compte_vente : '';
                        $achat = Imputation::estUneException($article, 'compte_achat') ? $article->compte_achat : '';
                        $variation = Imputation::variationEstUneException($article) ? $article->compte_variation : '';
                        $heriteVente = Imputation::compteHerite($article, 'compte_vente');
                        $heriteAchat = Imputation::compteHerite($article, 'compte_achat');
                        $heriteVariation = Imputation::variationHeritee($article);
                    @endphp
                    <tr data-article="{{ mb_strtolower($article->nom . ' ' . $article->reference) }}">
                        <td>{{ $article->nom }} <span style="color:var(--text-3); font-size:11.5px;">{{ $article->reference }}</span></td>
                        <td class="groupe cfg-propose">{{ $heriteVente }}</td>
                        <td><input type="text" class="form-control cfg-article" list="comptes-7"
                                   name="article[{{ $article->id }}][compte_vente]" value="{{ $vente }}" data-initial="{{ $vente }}" placeholder="{{ $heriteVente }}"></td>
                        <td class="groupe cfg-propose">{{ $heriteAchat }}</td>
                        <td><input type="text" class="form-control cfg-article" list="comptes-6"
                                   name="article[{{ $article->id }}][compte_achat]" value="{{ $achat }}" data-initial="{{ $achat }}" placeholder="{{ $heriteAchat }}"></td>
                        @if($variationEnPlace)
                            @if($article->estStockable())
                            <td class="groupe cfg-propose">{{ $heriteVariation ?: '—' }}</td>
                            <td><input type="text" class="form-control cfg-article" list="comptes-variation"
                                       name="article[{{ $article->id }}][compte_variation]" value="{{ $variation }}" data-initial="{{ $variation }}" placeholder="{{ $heriteVariation }}"></td>
                            @else
                            <td class="groupe" colspan="2"><span class="cfg-sans">Ne se stocke pas</span></td>
                            @endif
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            @endif
        </div>
    </div>

    <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Enregistrer la configuration</button>
</form>

<script>
document.querySelectorAll('[data-section]').forEach(function (section) {
    section.querySelectorAll('[data-onglet]').forEach(function (bouton) {
        bouton.addEventListener('click', function () {
            section.querySelectorAll('[data-onglet]').forEach(b => b.classList.toggle('actif', b === bouton));
            section.querySelectorAll('[data-panneau]').forEach(p => p.classList.toggle('actif', p.dataset.panneau === bouton.dataset.onglet));
        });
    });
});

document.querySelectorAll('.cfg-filtre').forEach(function (champ) {
    champ.addEventListener('input', function () {
        var q = champ.value.trim().toLowerCase();
        champ.closest('[data-panneau]').querySelectorAll('tr[data-article]').forEach(function (tr) {
            tr.style.display = !q || tr.dataset.article.indexOf(q) !== -1 ? '' : 'none';
        });
    });
});

// Seules les exceptions modifiées partent : un grand catalogue dépasserait
// sinon le nombre de champs qu'accepte le serveur.
document.getElementById('form-configuration-comptes').addEventListener('submit', function () {
    this.querySelectorAll('.cfg-article').forEach(function (champ) {
        if (champ.value.trim() === (champ.dataset.initial || '')) champ.disabled = true;
    });
});
</script>
@endsection
