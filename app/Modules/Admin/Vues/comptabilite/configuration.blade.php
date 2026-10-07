@extends('admin::gabarits.application')
@section('titre', 'Configuration comptable')
@section('topbar_titre', 'Comptabilité — Configuration comptable')

@php
    /*
     * Un menu de comptes. Vide = « hérite » : la ligne laisse parler le niveau
     * suivant de la chaîne (voir ImputationService).
     */
    $menu = function (string $nom, ?string $valeur, $liste, string $vide) {
        $html = '<select name="' . e($nom) . '" class="form-control">';
        $html .= '<option value="">' . e($vide) . '</option>';
        foreach ($liste as $c) {
            $choisi = (string) $valeur === (string) $c->numero ? ' selected' : '';
            $html .= '<option value="' . e($c->numero) . '"' . $choisi . '>' . e($c->numero . ' — ' . $c->libelle) . '</option>';
        }
        return $html . '</select>';
    };
@endphp

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-sliders"></i> Configuration comptable</h1>
        <p>Les comptes se saisissent une fois, ici — et non sur chaque fiche produit.</p>
    </div>
</div>

<div style="margin-bottom:18px; padding:14px 18px; background:#eff6ff; border:1px solid #93c5fd; border-radius:10px; color:#1e3a8a;">
    <strong><i class="fas fa-circle-info"></i> Le plus précis l'emporte.</strong>
    <div style="font-size:12.5px; margin-top:6px; line-height:1.6;">
        Pour chaque article, le compte est cherché dans cet ordre :
        <strong>1.</strong> ses comptes propres, si sa fiche le demande ;
        <strong>2.</strong> la configuration de son <em>type</em> ;
        <strong>3.</strong> celle de sa <em>catégorie</em> ;
        <strong>4.</strong> la configuration <em>générale</em> ;
        <strong>5.</strong> à défaut, {{ $defauts['vente'] }} à la vente et {{ $defauts['achat'] }} à l'achat.
        Un champ laissé vide laisse parler le niveau suivant.
        <br>
        <strong>Les écritures déjà passées ne sont pas réécrites</strong> : la configuration vaut pour ce qui s'écrira désormais.
    </div>
</div>

@if(session('succes'))
    <div style="margin-bottom:18px; padding:14px 18px; background:#ecfdf5; border:1px solid #6ee7b7; border-radius:10px; color:#065f46;">
        <i class="fas fa-circle-check"></i> {{ session('succes') }}
    </div>
@endif

@if($errors->any())
    <div style="margin-bottom:18px; padding:14px 18px; background:#fef2f2; border:1px solid #fca5a5; border-radius:10px; color:#991b1b;">
        <i class="fas fa-triangle-exclamation"></i>
        <ul style="margin:6px 0 0 18px;">
            @foreach($errors->all() as $erreur)<li>{{ $erreur }}</li>@endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('admin.comptabilite.configuration.enregistrer') }}">
    @csrf
    @method('PUT')

    @foreach(['vente' => ['Configuration des ventes', 'fa-cash-register', 'compte_vente'], 'achat' => ['Configuration des achats', 'fa-cart-shopping', 'compte_achat']] as $sens => [$titre, $icone, $colonne])
    <div class="card config-section" style="padding:18px; margin-bottom:18px;" data-section="{{ $sens }}">
        <h3 style="margin:0 0 12px; font-size:15px;"><i class="fas {{ $icone }}"></i> {{ $titre }}</h3>

        {{-- Trois boutons, trois portées. Les trois panneaux restent dans le
             formulaire : en masquer un ne doit pas l'effacer à l'envoi. --}}
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px;">
            <button type="button" class="btn btn-sm btn-primary config-onglet" data-panneau="generale">Configuration générale</button>
            <button type="button" class="btn btn-sm btn-outline config-onglet" data-panneau="categorie">Par catégorie</button>
            <button type="button" class="btn btn-sm btn-outline config-onglet" data-panneau="type">Par type</button>
        </div>

        <div class="config-panneau" data-panneau="generale">
            <label class="form-label">
                {{ $sens === 'vente' ? 'Compte de vente de tous les produits' : 'Compte d\'achat de tous les produits' }}
            </label>
            {!! $menu("{$sens}[general]", $generale[$colonne] ?? null, $comptes[$sens], '— défaut : ' . $defauts[$sens] . ' —') !!}
        </div>

        <div class="config-panneau" data-panneau="categorie" style="display:none;">
            @if($categories->isEmpty())
                <p style="font-size:12.5px; color:var(--text-3);">Aucune catégorie : elles se créent depuis le catalogue ou le parcours de configuration.</p>
            @else
            <table class="table" style="width:100%;">
                <thead><tr><th>Catégorie</th><th>{{ $sens === 'vente' ? 'Compte de vente' : 'Compte d\'achat' }}</th></tr></thead>
                <tbody>
                @foreach($categories as $categorie)
                    <tr>
                        <td style="font-weight:600;">{{ $categorie->nom }}</td>
                        <td>{!! $menu("{$sens}[categorie][{$categorie->id}]", $categorie->$colonne, $comptes[$sens], '— suit la configuration générale —') !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>

        <div class="config-panneau" data-panneau="type" style="display:none;">
            <table class="table" style="width:100%;">
                <thead><tr><th>Type d'article</th><th>{{ $sens === 'vente' ? 'Compte de vente' : 'Compte d\'achat' }}</th></tr></thead>
                <tbody>
                @foreach($parType as $type => $ligne)
                    <tr>
                        <td style="font-weight:600;">{{ $ligne['libelle'] }}</td>
                        <td>{!! $menu("{$sens}[type][{$type}]", $ligne[$colonne] ?? null, $comptes[$sens], '— suit la catégorie —') !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endforeach

    {{-- Chantier 6.3 : les comptes de stock et de variation sont posés par le
         système, d'après la famille de chaque catégorie. Rien n'est demandé :
         c'est une lecture, qui accepte une correction. --}}
    <div class="card" style="padding:18px; margin-bottom:18px;">
        <h3 style="margin:0 0 6px; font-size:15px;"><i class="fas fa-boxes-stacked"></i> Stock et variations de stock</h3>
        <p style="font-size:12.5px; color:var(--text-3); margin:0 0 12px; line-height:1.55;">
            À chaque réception, l'article stockable entre au compte de stock de sa catégorie (31, 32, 33, 36…) par
            le compte de variation (6031, 6032, 6033 ou 736) ; à chaque sortie, le mouvement inverse. Ces comptes
            sont posés par le système. Ils ne passent pas par la configuration générale : un « compte de stock de
            tous les produits » rendrait le bilan faux. Un article dont la catégorie n'en porte pas ne tient pas
            d'inventaire permanent.
        </p>
        @if($categories->isEmpty())
            <p style="font-size:12.5px; color:var(--text-3);">Aucune catégorie.</p>
        @else
        <table class="table" style="width:100%;">
            <thead><tr><th>Catégorie</th><th>Compte de stock</th><th>Compte de variation</th></tr></thead>
            <tbody>
            @foreach($categories as $categorie)
                <tr>
                    <td style="font-weight:600;">{{ $categorie->nom }}</td>
                    <td>{!! $menu("stock[categorie][{$categorie->id}]", $categorie->compte_stock, $comptes['stock'], '— aucun —') !!}</td>
                    <td>{!! $menu("variation[categorie][{$categorie->id}]", $categorie->compte_variation, $comptes['variation'], '— aucun —') !!}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @endif
    </div>

    <div style="display:flex; justify-content:flex-end;">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer la configuration</button>
    </div>
</form>
@endsection

@section('scripts')
<script>
document.querySelectorAll('.config-section').forEach(function (section) {
    section.querySelectorAll('.config-onglet').forEach(function (bouton) {
        bouton.addEventListener('click', function () {
            section.querySelectorAll('.config-onglet').forEach(function (b) {
                b.classList.toggle('btn-primary', b === bouton);
                b.classList.toggle('btn-outline', b !== bouton);
            });
            section.querySelectorAll('.config-panneau').forEach(function (p) {
                p.style.display = p.dataset.panneau === bouton.dataset.panneau ? '' : 'none';
            });
        });
    });
});
</script>
@endsection
