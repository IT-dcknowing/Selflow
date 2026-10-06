@extends('admin::gabarits.application')
@section('titre', 'Configuration des comptes')
@section('topbar_titre', 'Comptabilité — Configuration des comptes')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-sitemap"></i> Configuration des comptes</h1>
        <p>Les comptes de vente et d'achat se saisissent une fois ici, et non sur chaque fiche produit.</p>
    </div>
</div>

<div style="margin-bottom:18px; padding:14px 18px; background:#eff6ff; border:1px solid #93c5fd; border-radius:10px; color:#1e3a8a; font-size:12.5px; line-height:1.6;">
    <strong><i class="fas fa-circle-info"></i> Le plus précis l'emporte.</strong>
    Un article prend d'abord le compte de son <strong>type</strong>, sinon celui de sa <strong>catégorie</strong>,
    sinon la <strong>configuration générale</strong>. Une fiche produit peut porter sa propre exception :
    elle passe alors devant tout. Un champ laissé vide n'impose rien — l'article garde le compte de sa
    famille, sinon {{ $defauts['compte_vente'] }} à la vente et {{ $defauts['compte_achat'] }} à l'achat.
    <br>Les écritures déjà passées ne sont pas réécrites.
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

<style>
    .cfg-onglets { display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap; }
    .cfg-onglets button { padding:7px 14px; border-radius:20px; border:1px solid var(--border); background:var(--surface); cursor:pointer; font-size:12.5px; font-weight:600; color:var(--text-2); }
    .cfg-onglets button.actif { background:var(--primary); color:#fff; border-color:var(--primary); }
    .cfg-panneau { display:none; }
    .cfg-panneau.actif { display:block; }
    .cfg-table { width:100%; border-collapse:collapse; font-size:13px; }
    .cfg-table th, .cfg-table td { padding:8px 10px; border-bottom:1px solid var(--border); text-align:left; }
    .cfg-table input { width:140px; font-family:ui-monospace, Menlo, Consolas, monospace; }
</style>

<form method="POST" action="{{ route('admin.comptabilite.configuration.enregistrer') }}">
    @csrf
    @method('PUT')

    @foreach(['compte_vente' => ['Configuration vente', 'comptes-7', 'fa-cash-register'], 'compte_achat' => ['Configuration achat', 'comptes-6', 'fa-truck-loading']] as $champ => [$titre, $liste, $icone])
    <div class="card" style="padding:22px 24px; margin-bottom:18px;" data-section="{{ $champ }}">
        <h2 style="font-size:15px; margin-bottom:12px;"><i class="fas {{ $icone }}" style="color:var(--primary);"></i> {{ $titre }}</h2>

        <div class="cfg-onglets" role="tablist">
            <button type="button" class="actif" data-onglet="general">Configuration générale</button>
            <button type="button" data-onglet="categorie">Par catégorie</button>
            <button type="button" data-onglet="type">Par type</button>
        </div>

        <div class="cfg-panneau actif" data-panneau="general">
            <label class="form-label">{{ $champ === 'compte_vente' ? 'Compte de vente de tous les articles' : "Compte d'achat de tous les articles" }}</label>
            <input type="text" class="form-control" list="{{ $liste }}" style="max-width:220px;"
                   name="config[general:][{{ $champ }}]" value="{{ $lignes['general:']->$champ ?? '' }}"
                   placeholder="{{ $defauts[$champ] }}">
        </div>

        <div class="cfg-panneau" data-panneau="categorie">
            @if($categories->isEmpty())
                <p style="color:var(--text-3); font-size:13px;">Aucune catégorie n'est encore créée.</p>
            @else
            <table class="cfg-table">
                <thead><tr><th>Catégorie</th><th>Compte de la famille</th><th>Compte imposé</th></tr></thead>
                <tbody>
                @foreach($categories as $cat)
                    <tr>
                        <td>{{ $cat->nom }}</td>
                        <td style="color:var(--text-3); font-family:monospace;">{{ $cat->$champ ?: '—' }}</td>
                        <td><input type="text" class="form-control" list="{{ $liste }}"
                                   name="config[categorie:{{ $cat->id }}][{{ $champ }}]"
                                   value="{{ $lignes['categorie:' . $cat->id]->$champ ?? '' }}"></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>

        <div class="cfg-panneau" data-panneau="type">
            <table class="cfg-table">
                <thead><tr><th>Type d'article</th><th>Compte imposé</th></tr></thead>
                <tbody>
                @foreach($types as $code => $libelle)
                    <tr>
                        <td>{{ $libelle }}</td>
                        <td><input type="text" class="form-control" list="{{ $liste }}"
                                   name="config[type:{{ $code }}][{{ $champ }}]"
                                   value="{{ $lignes['type:' . $code]->$champ ?? '' }}"></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endforeach

    {{-- 6.3 — Les comptes de stock et de variation : posés par le système,
         montrés ici, corrigeables. Rien n'est demandé. --}}
    <div class="card" style="padding:22px 24px; margin-bottom:18px;">
        <h2 style="font-size:15px; margin-bottom:6px;"><i class="fas fa-boxes-stacked" style="color:var(--primary);"></i> Variations de stock</h2>
        <p style="font-size:12.5px; color:var(--text-3); margin-bottom:12px; line-height:1.6;">
            Chaque entrée et chaque sortie de stock passe une écriture d'inventaire permanent : le compte de
            stock (classe 3) contre le compte de variation (603x pour les achats, 73x pour la production).
            Ils sont posés par catégorie. Vous pouvez les corriger ici.
        </p>
        @if($categories->isEmpty())
            <p style="color:var(--text-3); font-size:13px;">Aucune catégorie n'est encore créée.</p>
        @else
        <table class="cfg-table">
            <thead><tr><th>Catégorie</th><th>Compte de stock</th><th>Compte de variation</th></tr></thead>
            <tbody>
            @foreach($categories as $cat)
                <tr>
                    <td>{{ $cat->nom }}</td>
                    <td><input type="text" class="form-control" name="stock[{{ $cat->id }}][compte_stock]" value="{{ $cat->compte_stock }}"></td>
                    <td><input type="text" class="form-control" name="stock[{{ $cat->id }}][compte_variation]" value="{{ $cat->compte_variation }}"></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @endif
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
</script>
@endsection
