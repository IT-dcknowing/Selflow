@extends('admin::gabarits.application')

@section('titre', 'Supervision · Référentiel')
@section('topbar_titre', 'Supervision · Référentiel de préparamétrage')

@section('styles')
<style>
    .ref-kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:14px; margin-bottom:22px; }
    .ref-kpi { background:#fff; border:1px solid var(--border); border-radius:12px; padding:16px 18px; }
    .ref-kpi .val { font-size:26px; font-weight:800; color:var(--text-1); font-variant-numeric:tabular-nums; }
    .ref-kpi .lbl { font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-3); letter-spacing:.4px; margin-top:4px; }
    .ref-kpi .sub { font-size:11.5px; color:var(--text-3); margin-top:2px; }

    .ref-note { background:#f8fafc; border-left:3px solid var(--primary); border-radius:6px;
                padding:12px 16px; font-size:13px; color:var(--text-2); margin-bottom:22px; line-height:1.55; }

    .ref-barre { display:flex; gap:12px; align-items:end; flex-wrap:wrap; background:#fff;
                 border:1px solid var(--border); border-radius:12px; padding:14px 18px; margin-bottom:20px; }
    .ref-barre label { font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-3);
                       display:block; margin-bottom:4px; }

    .ref-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--border);
                 border-radius:12px; overflow:hidden; }
    .ref-table th { text-align:left; padding:10px 14px; font-size:10.5px; text-transform:uppercase;
                    letter-spacing:.5px; color:var(--text-3); background:#f8fafc; border-bottom:1px solid var(--border); white-space:nowrap; }
    .ref-table td { padding:11px 14px; border-bottom:1px solid var(--border); font-size:13px; vertical-align:middle; }
    .ref-table tr:last-child td { border-bottom:none; }
    .ref-table tbody tr:hover { background:#fafbfc; }
    .ref-table td.num { text-align:right; font-variant-numeric:tabular-nums; }

    .cadre-defilant { overflow-x:auto; margin-bottom:26px; }

    .mod { display:inline-block; font-size:10px; font-weight:700; letter-spacing:.5px; text-transform:uppercase;
           padding:3px 7px; border-radius:4px; margin-right:4px; background:rgba(0,43,92,.06); color:var(--primary); }
    .mod-aucun { background:#f1f5f9; color:var(--text-3); }

    .cpt { font-family:ui-monospace, 'SF Mono', Menlo, Consolas, monospace; font-size:12px; color:var(--primary); }
    .vide { color:var(--text-3); }

    .section-titre { font-size:13px; font-weight:700; color:var(--text-1); text-transform:uppercase;
                     letter-spacing:.5px; margin:30px 0 12px; display:flex; align-items:center; gap:8px; }
</style>
@endsection

@section('contenu')

<div class="ref-note">
    <strong>Consultation seule.</strong> Le référentiel se modifie dans le classeur
    <em>sellflow_parametrage_activites</em>, puis se recharge avec
    <code>php artisan db:seed --class=ReferentielSeeder</code>. Une correction faite ici
    serait perdue au prochain chargement, et personne ne saurait pourquoi.
</div>

<div class="ref-kpis">
    <div class="ref-kpi">
        <div class="val">{{ $compteurs['profils'] }}</div>
        <div class="lbl">Profils</div>
        <div class="sub">{{ $categories->count() }} catégories</div>
    </div>
    <div class="ref-kpi">
        <div class="val">{{ $compteurs['familles'] }}</div>
        <div class="lbl">Familles</div>
        <div class="sub">porteuses des comptes</div>
    </div>
    <div class="ref-kpi">
        <div class="val">{{ $compteurs['articles'] }}</div>
        <div class="lbl">Articles types</div>
        <div class="sub">prix et stock à saisir</div>
    </div>
    <div class="ref-kpi">
        <div class="val">{{ number_format($compteurs['comptes'], 0, ',', ' ') }}</div>
        <div class="lbl">Comptes OHADA</div>
        <div class="sub">{{ $compteurs['communs'] }} livrés à chaque entreprise</div>
    </div>
</div>

{{-- ── Le pivot : c'est le type d'article qui décide des comptes ── --}}
<div class="section-titre"><i class="fas fa-sitemap"></i> Types d'article — le pivot du référentiel</div>
<div class="cadre-defilant">
    <table class="ref-table">
        <thead>
            <tr>
                <th>Code</th><th>Libellé</th>
                <th>Vente</th><th>Achat</th><th>Stock</th><th>Variation</th>
                <th>Stockable</th>
            </tr>
        </thead>
        <tbody>
            @foreach($typesArticles as $type)
            <tr>
                <td><strong>{{ $type->code }}</strong></td>
                <td>{{ $type->libelle }}</td>
                @foreach(['compte_vente', 'compte_achat', 'compte_stock', 'compte_variation'] as $champ)
                    <td>
                        @if($type->$champ)
                            <span class="cpt">{{ $type->$champ }}</span>
                        @else
                            <span class="vide">—</span>
                        @endif
                    </td>
                @endforeach
                <td>{!! $type->estStockable() ? '<i class="fas fa-check" style="color:#059669"></i>' : '<span class="vide">non</span>' !!}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{-- ── Journaux livrés à la création d'une entreprise ── --}}
<div class="section-titre"><i class="fas fa-book"></i> Journaux livrés à chaque entreprise</div>
<div class="cadre-defilant">
    <table class="ref-table">
        <thead><tr><th>Code</th><th>Type</th><th>Intitulé</th><th>Compte</th><th>Nature</th></tr></thead>
        <tbody>
            @foreach($journaux as $journal)
            <tr>
                <td><strong>{{ $journal['code'] }}</strong></td>
                <td>{{ $journal['type'] }}</td>
                <td>{{ $journal['intitule'] }}</td>
                <td>
                    @if($journal['compte'])
                        <span class="cpt">{{ $journal['compte'] }}</span>
                    @else
                        <span class="vide">aucun</span>
                    @endif
                </td>
                <td>
                    @if($journal['systeme'] ?? false)
                        <span class="mod">Système</span>
                    @elseif($journal['renommable'] ?? false)
                        <span class="vide">renommable par l'utilisateur</span>
                    @else
                        <span class="vide">—</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{-- ── Les profils ── --}}
<div class="section-titre"><i class="fas fa-briefcase"></i> Profils d'activité</div>

<form method="GET" class="ref-barre">
    <div class="form-group" style="margin-bottom:0;">
        <label>Catégorie</label>
        <select name="categorie" class="form-control" onchange="this.form.submit()">
            <option value="">Toutes ({{ $compteurs['profils'] }})</option>
            @foreach($categories as $categorie)
                <option value="{{ $categorie->id }}" {{ request('categorie') == $categorie->id ? 'selected' : '' }}>
                    {{ $categorie->nom }} ({{ $categorie->profils_count }})
                </option>
            @endforeach
        </select>
    </div>
    <div class="form-group" style="margin-bottom:0; flex:1; min-width:200px;">
        <label>Recherche</label>
        <input type="text" name="recherche" class="form-control" value="{{ request('recherche') }}"
               placeholder="Nom ou code du profil…">
    </div>
    <button class="btn btn-primary"><i class="fas fa-magnifying-glass"></i> Filtrer</button>
    @if(request()->hasAny(['categorie', 'recherche']))
        <a href="{{ route('superadmin.referentiel.index') }}" class="btn btn-outline">
            <i class="fas fa-rotate-left"></i> Réinitialiser
        </a>
    @endif
</form>

<div class="cadre-defilant">
    <table class="ref-table">
        <thead>
            <tr>
                <th>Profil</th><th>Catégorie</th><th>Modules ouverts</th>
                <th class="num">Familles</th><th class="num">Articles</th><th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($profils as $profil)
            <tr>
                <td>
                    <strong>{{ $profil->nom }}</strong>
                    <div class="vide" style="font-size:11.5px;">{{ $profil->code }}</div>
                </td>
                <td>{{ $profil->categorie->nom }}</td>
                <td>
                    @forelse($profil->modulesOuverts() as $module)
                        <span class="mod">{{ $module }}</span>
                    @empty
                        <span class="mod mod-aucun">aucun</span>
                    @endforelse
                </td>
                <td class="num">{{ $profil->familles_count }}</td>
                <td class="num">{{ $profil->articles_count }}</td>
                <td style="text-align:right;">
                    <a href="{{ route('superadmin.referentiel.profil', $profil->code) }}" class="btn btn-outline btn-sm">
                        <i class="fas fa-eye"></i> Détail
                    </a>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" style="text-align:center; padding:30px; color:var(--text-3);">
                Aucun profil ne correspond à cette recherche.
            </td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@endsection
